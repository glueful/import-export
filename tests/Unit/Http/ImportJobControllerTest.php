<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCancelled;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportJobController;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class ImportJobControllerTest extends ImportExportTestCase
{
    public function testCreateImportQueuesBatchesAndReturnsLinks(): void
    {
        $queue = new FakeQueueManager();
        $controller = $this->controller($queue);
        $this->seedSourceFile('imports/content.ndjson');
        $request = $this->jsonRequest('/import-export/imports', [
            'adapter' => 'fake',
            'disk' => 'uploads',
            'path' => 'imports/content.ndjson',
            'mode' => 'commit',
        ]);
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $response = $controller->store($request);
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('queued', $data['data']['job']['status']);
        self::assertSame('user-1', $data['data']['job']['created_by']);
        self::assertSame('/import-export/jobs/' . $data['data']['job']['uuid'], $data['data']['job']['links']['self']);
        self::assertCount(1, $queue->pushed);
    }

    public function testStatusErrorsAndCancelEndpointsUseRepositories(): void
    {
        $dispatcher = new ImportJobControllerRecordingDispatcher();
        $controller = $this->controller(new FakeQueueManager(), new EventService($dispatcher, new ListenerProvider()));
        $job = $this->seedJob(['status' => 'queued', 'type' => 'import', 'adapter' => 'fake']);
        $this->seedBatch(['job_uuid' => $job['uuid']]);
        $errors = new ImportExportErrorRepository($this->connection(), new ImportExportJobRepository($this->connection()));
        $errors->record($job['uuid'], null, ['message' => 'Bad row']);

        $show = $this->json($controller->show(Request::create('/'), $job['uuid']));
        $errorData = $this->json($controller->errors(Request::create('/'), $job['uuid']));
        $cancel = $this->json($controller->cancel(Request::create('/'), $job['uuid']));

        self::assertSame($job['uuid'], $show['data']['job']['uuid']);
        self::assertCount(1, $show['data']['batches']);
        self::assertSame('Bad row', $errorData['data']['errors'][0]['message']);
        self::assertSame('cancelled', $cancel['data']['job']['status']);
        self::assertInstanceOf(ImportExportJobCancelled::class, $dispatcher->events[0] ?? null);
    }

    public function testListCanFilterJobsByTypeAndStatus(): void
    {
        $controller = $this->controller(new FakeQueueManager());
        $this->seedJob(['type' => 'import', 'status' => 'queued']);
        $this->seedJob(['type' => 'export', 'status' => 'queued']);

        $data = $this->json($controller->index(Request::create('/import-export/jobs?type=import&status=queued')));

        self::assertCount(1, $data['data']['jobs']);
        self::assertSame('import', $data['data']['jobs'][0]['type']);
    }

    private function controller(FakeQueueManager $queue, ?EventService $events = null): ImportJobController
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());
        $errors = new ImportExportErrorRepository($this->connection(), $jobs);

        return new ImportJobController(
            $this->appContext(),
            new ImportExportService(
                $this->appContext(),
                new ImporterRegistry([
                    new FakeImporter('fake', new ImportPlan(10, [
                        new ImportBatch('batch-1', 'job-1', 1, 0, 10),
                    ], retryable: true)),
                ]),
                new ExporterRegistry([]),
                $jobs,
                $batches,
                new ImportExportFileRepository($this->connection()),
                $queue
            ),
            $jobs,
            $batches,
            $errors,
            $events
        );
    }

    /** @param array<string,mixed> $payload */
    private function jsonRequest(string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

final class ImportJobControllerRecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
