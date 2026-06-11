<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Queue\QueueManager;

final class RetryService
{
    public function __construct(
        private ImporterRegistry $importers,
        private ExporterRegistry $exporters,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private QueueManager $queue,
        private string $queueName = 'import-export',
    ) {
    }

    public function retry(string $jobUuid): void
    {
        $job = $this->jobs->find($jobUuid);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Import/export job "%s" was not found.', $jobUuid));
        }

        $adapter = $this->adapterFor($job);
        if (!$adapter instanceof RetryableAdapterInterface || !$adapter->retryable()) {
            throw new \RuntimeException(sprintf('Adapter "%s" is not retryable.', $job['adapter']));
        }

        $failedBatches = $this->batches->failedForJob($jobUuid);
        foreach ($failedBatches as $batch) {
            $this->batches->resetForRetry((string) $batch['uuid']);
            $this->queue->push($job['type'] === 'export' ? ProcessExportBatchJob::class : ProcessImportBatchJob::class, [
                'job_uuid' => $jobUuid,
                'batch_uuid' => $batch['uuid'],
                'adapter' => $job['adapter'],
                'retryable' => true,
            ], $this->queueName);
        }

        if ($failedBatches !== []) {
            $this->jobs->transition($jobUuid, 'queued');
        }
    }

    /** @param array<string,mixed> $job */
    private function adapterFor(array $job): object
    {
        return $job['type'] === 'export'
            ? $this->exporters->get((string) $job['adapter'])
            : $this->importers->get((string) $job['adapter']);
    }
}
