<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Fakes;

use Glueful\Extensions\ImportExport\Contracts\ExporterInterface;
use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportBatchResult;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;

final class FakeExporter implements ExporterInterface, RetryableAdapterInterface
{
    public bool $processed = false;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Exporter';
    }

    public function plan(ExportOptions $options): ExportPlan
    {
        return new ExportPlan(2, [
            new ExportBatch('fake-export-1', 'pending', 1, 0, 2),
        ], retryable: true);
    }

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult
    {
        $this->processed = true;

        return new ExportBatchResult(2, 0, [], 'exports/fake.ndjson');
    }

    public function retryable(): bool
    {
        return true;
    }
}
