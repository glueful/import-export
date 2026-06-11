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
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Tests\Fakes\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Fakes\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class CompleteExportFlowTest extends ImportExportTestCase
{
    public function testCompleteExportFlowCreatesProcessesResultFileAndReportsJob(): void
    {
        $exporter = new FakeExporter();
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());
        $files = new ImportExportFileRepository($this->connection());
        $errors = new ImportExportErrorRepository($this->connection(), $jobs);
        $reports = new ImportExportReportRepository($this->connection());
        $service = new ImportExportService(
            new ImporterRegistry([new FakeImporter()]),
            new ExporterRegistry([$exporter]),
            $jobs,
            $batches,
            $files,
            new FakeQueueManager()
        );

        $job = $service->createExport('fake', new ExportOptions());
        $batch = $batches->forJob($job['uuid'])[0];
        $runner = new BatchRunner(
            $this->appContext(),
            new ImporterRegistry([new FakeImporter()]),
            new ExporterRegistry([$exporter]),
            $jobs,
            $batches,
            $errors,
            $files
        );

        $runner->runExportBatch($batch['uuid']);
        $report = (new ReportBuilder($jobs, $errors, $reports))->build($job['uuid']);
        $stored = $jobs->find($job['uuid']);
        $resultFiles = $files->forJob($job['uuid'], 'result');

        self::assertTrue($exporter->processed);
        self::assertSame('completed', $stored['status']);
        self::assertSame(2, (int) $stored['processed_records']);
        self::assertSame('exports/fake.ndjson', $resultFiles[0]['path']);
        self::assertSame($job['uuid'], $report['job_uuid']);
    }
}
