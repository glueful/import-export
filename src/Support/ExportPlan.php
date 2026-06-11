<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ExportPlan
{
    /**
     * @param list<ExportBatch> $batches
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public int $totalRecords,
        public array $batches,
        public bool $retryable,
        public array $metadata = [],
    ) {
    }
}
