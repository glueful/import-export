<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ExportOptions
{
    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $format = 'ndjson',
        public int $batchSize = 500,
        public ?string $actorUuid = null,
        public array $filters = [],
        public array $options = [],
    ) {
    }
}
