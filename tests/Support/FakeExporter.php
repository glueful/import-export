<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Support;

use Glueful\Extensions\ImportExport\Contracts\ExporterInterface;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportBatchResult;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;

final class FakeExporter implements ExporterInterface
{
    public function __construct(private string $key, private ?ExportPlan $plan = null)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake Exporter';
    }

    public function plan(ExportOptions $options): ExportPlan
    {
        return $this->plan ?? new ExportPlan(0, [], retryable: true);
    }

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult
    {
        return new ExportBatchResult(0, 0, [], null);
    }
}
