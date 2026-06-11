<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ImportSource
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $mimeType = null,
        public array $metadata = [],
    ) {
    }
}
