<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ImportBatch
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $uuid,
        public string $jobUuid,
        public int $sequence,
        public int $offset,
        public int $limit,
        public array $metadata = [],
    ) {
    }
}
