<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Fakes;

use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;

final class FakeImporter implements ImporterInterface, RetryableAdapterInterface
{
    public bool $processed = false;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Importer';
    }

    public function supports(ImportSource $source): bool
    {
        return $source->path !== '';
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        return new ImportPlan(2, [
            new ImportBatch('fake-import-1', 'pending', 1, 0, 2),
        ], retryable: true);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        $this->processed = true;

        return new ImportBatchResult(2, 0, []);
    }

    public function retryable(): bool
    {
        return true;
    }
}
