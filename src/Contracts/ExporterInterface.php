<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Contracts;

use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportBatchResult;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;

interface ExporterInterface
{
    public function key(): string;

    public function label(): string;

    public function plan(ExportOptions $options): ExportPlan;

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult;
}
