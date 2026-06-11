<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final readonly class ImportOptions
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $mode = 'dry_run',
        public int $batchSize = 500,
        public ?string $actorUuid = null,
        public array $options = [],
    ) {
    }
}
