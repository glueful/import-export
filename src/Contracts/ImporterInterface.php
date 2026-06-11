<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Contracts;

use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;

interface ImporterInterface
{
    public function key(): string;

    public function label(): string;

    public function supports(ImportSource $source): bool;

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan;

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult;
}
