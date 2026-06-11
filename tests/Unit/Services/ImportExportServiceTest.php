<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ImportExportServiceTest extends ImportExportTestCase
{
    public function testCreateImportJobPlansBatchesAndEnqueuesJobs(): void
    {
        $queue = new FakeQueueManager();
        $service = $this->service(
            importer: new FakeImporter('wordpress', new ImportPlan(2, [
                new ImportBatch('batch-a', 'pending', 1, 0, 1),
                new ImportBatch('batch-b', 'pending', 2, 1, 1),
            ], retryable: true)),
            queue: $queue,
        );

        $job = $service->createImport('wordpress', new ImportSource('uploads', 'wordpress.zip'), new ImportOptions());

        $this->assertSame('import', $job['type']);
        $this->assertSame('wordpress', $job['adapter']);
        $this->assertSame('queued', $job['status']);
        $this->assertCount(2, $queue->pushed);
        $this->assertSame(ProcessImportBatchJob::class, $queue->pushed[0]['job']);
    }

    public function testCreateImportPersistsAdapterOptionsForBatchProcessing(): void
    {
        $service = $this->service(
            importer: new FakeImporter('wordpress', new ImportPlan(1, [
                new ImportBatch('batch-a', 'pending', 1, 0, 1),
            ], retryable: true)),
        );

        $job = $service->createImport(
            'wordpress',
            new ImportSource('uploads', 'wordpress.zip'),
            new ImportOptions(options: ['site' => 'main'])
        );

        $this->assertSame(['site' => 'main'], json_decode((string) $job['options'], true));
    }

    public function testCreateExportJobPlansBatchesAndEnqueuesJobs(): void
    {
        $queue = new FakeQueueManager();
        $service = $this->service(
            exporter: new FakeExporter('entries', new ExportPlan(1, [
                new ExportBatch('batch-a', 'pending', 1, 0, 1),
            ], retryable: true)),
            queue: $queue,
        );

        $job = $service->createExport('entries', new ExportOptions(format: 'ndjson'));

        $this->assertSame('export', $job['type']);
        $this->assertSame('entries', $job['adapter']);
        $this->assertSame('ndjson', $job['format']);
        $this->assertSame('uploads', $job['result_disk']);
        $this->assertSame(ProcessExportBatchJob::class, $queue->pushed[0]['job']);
    }

    public function testCreateExportPersistsFormatFiltersOptionsAndResultDisk(): void
    {
        $service = $this->service(
            exporter: new FakeExporter('entries', new ExportPlan(1, [
                new ExportBatch('batch-a', 'pending', 1, 0, 1),
            ], retryable: true)),
        );

        $job = $service->createExport('entries', new ExportOptions(
            format: 'csv',
            filters: ['status' => 'published'],
            options: ['include_drafts' => false]
        ));

        $this->assertSame('csv', $job['format']);
        $this->assertSame('uploads', $job['result_disk']);
        $this->assertSame(['status' => 'published'], json_decode((string) $job['filters'], true));
        $this->assertSame(['include_drafts' => false], json_decode((string) $job['options'], true));
    }

    private function service(
        ?FakeImporter $importer = null,
        ?FakeExporter $exporter = null,
        ?FakeQueueManager $queue = null,
    ): ImportExportService {
        return new ImportExportService(
            $this->appContext(),
            new ImporterRegistry($importer === null ? [] : [$importer]),
            new ExporterRegistry($exporter === null ? [] : [$exporter]),
            new ImportExportJobRepository($this->connection()),
            new ImportExportBatchRepository($this->connection()),
            new ImportExportFileRepository($this->connection()),
            $queue ?? new FakeQueueManager(),
            queueName: 'imports',
        );
    }
}
