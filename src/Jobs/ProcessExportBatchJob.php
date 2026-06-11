<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Queue\Job;

use function app;

final class ProcessExportBatchJob extends Job
{
    public function __construct(
        array $data = [],
        ?ApplicationContext $context = null,
        private ?BatchRunner $runner = null,
    ) {
        parent::__construct($data, $context);
    }

    public function handle(): void
    {
        try {
            $this->resolveRunner()->runExportBatch((string) ($this->getData()['batch_uuid'] ?? ''));
        } catch (\Throwable $e) {
            error_log('Export batch job failed without queue retry: ' . $e->getMessage());
        }
    }

    public function getMaxAttempts(): int
    {
        return 1;
    }

    public function shouldRetry(): bool
    {
        return false;
    }

    private function resolveRunner(): BatchRunner
    {
        if ($this->runner !== null) {
            return $this->runner;
        }

        if ($this->context === null) {
            throw new \RuntimeException('BatchRunner cannot be resolved without an ApplicationContext.');
        }

        return app($this->context, BatchRunner::class);
    }
}
