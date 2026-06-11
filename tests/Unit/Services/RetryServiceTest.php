<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class RetryServiceTest extends ImportExportTestCase
{
    public function testRetryRefusesNonRetryableAdapter(): void
    {
        $job = $this->seedJob(['status' => 'failed', 'adapter' => 'non_retryable']);
        $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'failed']);
        $service = $this->retryService(new FakeImporter('non_retryable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not retryable');

        $service->retry($job['uuid']);
    }

    public function testRetryRequeuesFailedBatchesForRetryableAdapter(): void
    {
        $job = $this->seedJob(['status' => 'failed', 'adapter' => 'retryable']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'failed', 'attempts' => 1]);
        $queue = new FakeQueueManager();
        $service = $this->retryService(new RetryableFakeImporter('retryable'), $queue);

        $service->retry($job['uuid']);

        $jobRow = (new ImportExportJobRepository($this->connection()))->find($job['uuid']);
        $batchRow = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);

        $this->assertSame('queued', $jobRow['status']);
        $this->assertSame('pending', $batchRow['status']);
        $this->assertCount(1, $queue->pushed);
    }

    private function retryService(FakeImporter $importer, ?FakeQueueManager $queue = null): RetryService
    {
        return new RetryService(
            new ImporterRegistry([$importer]),
            new ExporterRegistry([]),
            new ImportExportJobRepository($this->connection()),
            new ImportExportBatchRepository($this->connection()),
            $queue ?? new FakeQueueManager(),
            queueName: 'imports',
        );
    }
}

final class RetryableFakeImporter extends FakeImporter implements RetryableAdapterInterface
{
    public function retryable(): bool
    {
        return true;
    }
}
