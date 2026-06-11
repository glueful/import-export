<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Support;

use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;

class FakeImporter implements ImporterInterface
{
    public bool $processed = false;
    public ?string $lastMode = null;
    public ?ImportOptions $lastPlanOptions = null;
    public ?ImportContext $lastContext = null;

    public function __construct(
        private string $key,
        private ?ImportPlan $plan = null,
        private ?ImportBatchResult $batchResult = null,
        private ?\Throwable $throw = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake Importer';
    }

    public function supports(ImportSource $source): bool
    {
        return true;
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $this->lastPlanOptions = $options;

        return $this->plan ?? new ImportPlan(0, [], retryable: true);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->processed = true;
        $this->lastMode = $context->mode;
        $this->lastContext = $context;

        return $this->batchResult ?? new ImportBatchResult(0, 0, []);
    }
}
