<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportJobFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobStarted;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class BatchRunnerTest extends ImportExportTestCase
{
    public function testCancelledJobSkipsFutureBatch(): void
    {
        $job = $this->seedJob(['status' => 'cancelled']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $importer = new FakeImporter('fake');
        $runner = $this->runner($importer);

        $runner->runImportBatch($batch['uuid']);

        $this->assertFalse($importer->processed);
    }

    public function testDryRunProcessesBatchWithoutCommitFlag(): void
    {
        $job = $this->seedJob(['status' => 'running', 'mode' => 'dry_run']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $importer = new FakeImporter('fake', batchResult: new ImportBatchResult(3, 0, []));
        $runner = $this->runner($importer);

        $runner->runImportBatch($batch['uuid']);

        $row = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);
        $this->assertTrue($importer->processed);
        $this->assertSame('dry_run', $importer->lastMode);
        $this->assertSame('completed', $row['status']);
        $this->assertSame(3, (int) $row['processed_records']);
    }

    public function testImportBatchReceivesPersistedOptions(): void
    {
        $job = $this->seedJob([
            'status' => 'running',
            'mode' => 'commit',
            'options' => json_encode(['locale' => 'en'], JSON_THROW_ON_ERROR),
        ]);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $importer = new FakeImporter('fake', batchResult: new ImportBatchResult(1, 0, []));

        $this->runner($importer)->runImportBatch($batch['uuid']);

        $this->assertSame(['locale' => 'en'], $importer->lastContext?->options);
    }

    public function testThrowingImporterMarksBatchAndJobFailedAndRecordsError(): void
    {
        $job = $this->seedJob(['status' => 'queued', 'mode' => 'commit']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $runner = $this->runner(new FakeImporter('fake', throw: new \RuntimeException('Adapter exploded')));

        $runner->runImportBatch($batch['uuid']);

        $storedBatch = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);
        $storedJob = (new ImportExportJobRepository($this->connection()))->find($job['uuid']);
        $errors = (new ImportExportErrorRepository(
            $this->connection(),
            new ImportExportJobRepository($this->connection())
        ))->forJob($job['uuid']);

        self::assertSame('failed', $storedBatch['status']);
        self::assertSame('failed', $storedJob['status']);
        self::assertSame(1, (int) $storedJob['failed_records']);
        self::assertSame('adapter_exception', $errors[0]['code']);
        self::assertSame('Adapter failed while processing the batch.', $errors[0]['message']);
    }

    public function testThrowingExporterMarksBatchAndJobFailedAndRecordsError(): void
    {
        $job = $this->seedJob(['status' => 'queued', 'type' => 'export', 'adapter' => 'fake']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $runner = $this->runner(
            new FakeImporter('fake'),
            new FakeExporter('fake', throw: new \RuntimeException('Export exploded'))
        );

        $runner->runExportBatch($batch['uuid']);

        $storedBatch = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);
        $storedJob = (new ImportExportJobRepository($this->connection()))->find($job['uuid']);
        $errors = (new ImportExportErrorRepository(
            $this->connection(),
            new ImportExportJobRepository($this->connection())
        ))->forJob($job['uuid']);

        self::assertSame('failed', $storedBatch['status']);
        self::assertSame('failed', $storedJob['status']);
        self::assertSame(1, (int) $storedJob['failed_records']);
        self::assertSame('adapter_exception', $errors[0]['code']);
        self::assertSame('Adapter failed while processing the batch.', $errors[0]['message']);
    }

    public function testSuccessfulExporterProcessesBatch(): void
    {
        $job = $this->seedJob(['status' => 'queued', 'type' => 'export', 'adapter' => 'fake']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $runner = $this->runner(
            new FakeImporter('fake'),
            new FakeExporter('fake')
        );

        $runner->runExportBatch($batch['uuid']);

        $row = (new ImportExportBatchRepository($this->connection()))->find($batch['uuid']);
        self::assertSame('completed', $row['status']);
    }

    public function testExportBatchReceivesPersistedFormatAndOptions(): void
    {
        $job = $this->seedJob([
            'status' => 'queued',
            'type' => 'export',
            'adapter' => 'fake',
            'format' => 'csv',
            'filters' => json_encode(['status' => 'published'], JSON_THROW_ON_ERROR),
            'options' => json_encode(['include_media' => true], JSON_THROW_ON_ERROR),
        ]);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);
        $exporter = new FakeExporter('fake');

        $this->runner(new FakeImporter('fake'), $exporter)->runExportBatch($batch['uuid']);

        self::assertSame('csv', $exporter->lastContext?->format);
        self::assertSame(['status' => 'published'], $exporter->lastContext?->filters);
        self::assertSame(['include_media' => true], $exporter->lastContext?->options);
    }

    public function testSuccessfulBatchDispatchesLifecycleEvents(): void
    {
        $dispatcher = new BatchRunnerRecordingDispatcher();
        $events = new EventService($dispatcher, new ListenerProvider());
        $job = $this->seedJob(['status' => 'queued', 'mode' => 'commit']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);

        $this->runner(
            new FakeImporter('fake', batchResult: new ImportBatchResult(3, 0, [])),
            events: $events
        )->runImportBatch($batch['uuid']);

        self::assertSame([
            ImportExportJobStarted::class,
            ImportExportBatchCompleted::class,
            ImportExportJobCompleted::class,
        ], array_map(static fn(object $event): string => $event::class, $dispatcher->events));
    }

    public function testFailedBatchDispatchesLifecycleEvents(): void
    {
        $dispatcher = new BatchRunnerRecordingDispatcher();
        $events = new EventService($dispatcher, new ListenerProvider());
        $job = $this->seedJob(['status' => 'queued', 'mode' => 'commit']);
        $batch = $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'pending']);

        $this->runner(
            new FakeImporter('fake', throw: new \RuntimeException('Adapter exploded')),
            events: $events
        )->runImportBatch($batch['uuid']);

        self::assertSame([
            ImportExportJobStarted::class,
            ImportExportBatchFailed::class,
            ImportExportJobFailed::class,
        ], array_map(static fn(object $event): string => $event::class, $dispatcher->events));
    }

    private function runner(
        FakeImporter $importer,
        ?FakeExporter $exporter = null,
        ?EventService $events = null,
    ): BatchRunner
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());

        return new BatchRunner(
            $this->appContext(),
            new ImporterRegistry([$importer]),
            new ExporterRegistry([$exporter ?? new FakeExporter('fake')]),
            $jobs,
            $batches,
            new ImportExportErrorRepository($this->connection(), $jobs),
            new ImportExportFileRepository($this->connection()),
            $events,
        );
    }
}

final class BatchRunnerRecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
