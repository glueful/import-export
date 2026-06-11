<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Events;

use Glueful\Events\Contracts\BaseEvent;

final class ImportExportJobFailed extends BaseEvent
{
    public function __construct(
        public readonly string $jobUuid,
        public readonly string $type,
        public readonly string $adapter,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}
