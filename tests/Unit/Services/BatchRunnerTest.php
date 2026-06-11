<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class BatchRunnerTest extends ImportExportTestCase
{
    public function testCancelledJobSkipsFutureBatch(): void
    {
        $job = $this->seedJob(['status' => 'cancelled']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $importer = new FakeImporter('fake');
        $runner = $this->runner($importer);

        $runner->runImportBatch($batch['uuid']);

        $this->assertFalse($importer->processed);
    }

    public function testDryRunProcessesBatchWithoutCommitFlag(): void
    {
        $job = $this->seedJob(['status' => 'running', 'mode' => 'dry_run']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $importer = new FakeImporter('fake', batchResult: new ImportBatchResult(3, 0, []));
        $runner = $this->runner($importer);

        $runner->runImportBatch($batch['uuid']);

        $row = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);
        $this->assertTrue($importer->processed);
        $this->assertSame('dry_run', $importer->lastMode);
        $this->assertSame('completed', $row['status']);
        $this->assertSame(3, (int) $row['processed_records']);
    }

    private function runner(FakeImporter $importer): BatchRunner
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());

        return new BatchRunner(
            $this->appContext(),
            new ImporterRegistry([$importer]),
            new ExporterRegistry([new FakeExporter('fake')]),
            $jobs,
            $batches,
            new ImportExportErrorRepository($this->connection(), $jobs),
            new ImportExportFileRepository($this->connection()),
        );
    }
}
