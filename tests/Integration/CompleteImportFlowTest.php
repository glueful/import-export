<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Integration;

use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Extensions\ImportExport\Tests\Fakes\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Fakes\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class CompleteImportFlowTest extends ImportExportTestCase
{
    public function testCompleteImportFlowCreatesProcessesAndReportsJob(): void
    {
        $importer = new FakeImporter();
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());
        $files = new ImportExportFileRepository($this->connection());
        $errors = new ImportExportErrorRepository($this->connection(), $jobs);
        $reports = new ImportExportReportRepository($this->connection());
        $service = new ImportExportService(
            $this->appContext(),
            new ImporterRegistry([$importer]),
            new ExporterRegistry([new FakeExporter()]),
            $jobs,
            $batches,
            $files,
            new FakeQueueManager()
        );
        $this->seedSourceFile('imports/fake.ndjson');

        $job = $service->createImport(
            'fake',
            new ImportSource('uploads', 'imports/fake.ndjson'),
            new ImportOptions(mode: 'commit')
        );
        $batch = $batches->forJob($job['uuid'])[0];
        $runner = new BatchRunner(
            $this->appContext(),
            new ImporterRegistry([$importer]),
            new ExporterRegistry([new FakeExporter()]),
            $jobs,
            $batches,
            $errors,
            $files
        );

        $runner->runImportBatch($batch['uuid']);
        $report = (new ReportBuilder($jobs, $errors, $reports))->build($job['uuid']);
        $stored = $jobs->find($job['uuid']);

        self::assertTrue($importer->processed);
        self::assertSame('completed', $stored['status']);
        self::assertSame(2, (int) $stored['processed_records']);
        self::assertSame($job['uuid'], $report['job_uuid']);
    }
}
