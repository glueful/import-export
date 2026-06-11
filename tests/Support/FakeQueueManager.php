<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Support;

use Glueful\Queue\QueueManager;

final class FakeQueueManager extends QueueManager
{
    /** @var list<array{job:string,data:array<string,mixed>,queue:?string,connection:?string}> */
    public array $pushed = [];

    public function __construct()
    {
    }

    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        $this->pushed[] = compact('job', 'data', 'queue', 'connection');

        return 'queued-' . count($this->pushed);
    }
}
