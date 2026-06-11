<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCreated;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ImportExportEventDispatchTest extends ImportExportTestCase
{
    public function testCreateImportDispatchesJobCreatedEvent(): void
    {
        $dispatcher = new RecordingDispatcher();
        $events = new EventService($dispatcher, new ListenerProvider());
        $service = new ImportExportService(
            $this->appContext(),
            new ImporterRegistry([new FakeImporter('wordpress')]),
            new ExporterRegistry([]),
            new ImportExportJobRepository($this->connection()),
            new ImportExportBatchRepository($this->connection()),
            new ImportExportFileRepository($this->connection()),
            new FakeQueueManager(),
            queueName: 'imports',
            events: $events,
        );

        $service->createImport('wordpress', new ImportSource('uploads', 'wordpress.zip'), new ImportOptions());

        $this->assertInstanceOf(ImportExportJobCreated::class, $dispatcher->events[0]);
    }
}

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
