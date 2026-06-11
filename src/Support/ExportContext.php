<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

use Glueful\Bootstrap\ApplicationContext;

final readonly class ExportContext
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public ApplicationContext $app,
        public string $jobUuid,
        public string $format,
        public ?string $actorUuid = null,
        public array $options = [],
    ) {
    }
}
