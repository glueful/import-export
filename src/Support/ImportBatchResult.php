<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ImportBatchResult
{
    /**
     * @param list<array<string,mixed>> $errors
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public int $processedRecords,
        public int $failedRecords,
        public array $errors,
        public array $metadata = [],
    ) {
    }
}
