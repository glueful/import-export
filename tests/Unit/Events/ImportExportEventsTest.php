<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCancelled;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCreated;
use Glueful\Extensions\ImportExport\Events\ImportExportJobFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobStarted;
use PHPUnit\Framework\TestCase;

final class ImportExportEventsTest extends TestCase
{
    public function testEventsExtendBaseEventAndExposePayload(): void
    {
        $events = [
            new ImportExportJobCreated('job1', 'import', 'wordpress'),
            new ImportExportJobStarted('job1', 'import', 'wordpress'),
            new ImportExportBatchCompleted('job1', 'batch1', 'import', 'wordpress'),
            new ImportExportBatchFailed('job1', 'batch1', 'import', 'wordpress', 'bad row'),
            new ImportExportJobCompleted('job1', 'import', 'wordpress'),
            new ImportExportJobFailed('job1', 'import', 'wordpress', 'failed'),
            new ImportExportJobCancelled('job1', 'import', 'wordpress'),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(BaseEvent::class, $event);
            $this->assertSame('job1', $event->jobUuid);
            $this->assertNotSame('', $event->getEventId());
        }
    }
}
