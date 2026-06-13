<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Console;

use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\ImportExport\Console\ExportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ExportListCommand;
use Glueful\Extensions\ImportExport\Console\ImportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCancelCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCleanupCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportFailedRecordsCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportRetryCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportStatusCommand;
use Glueful\Extensions\ImportExport\Console\ImportListCommand;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCancelled;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Extensions\ImportExport\Services\RetentionCleaner;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportPlan;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportExportCommandsTest extends ImportExportTestCase
{
    public function testImportAndExportRunCommandsQueueJobs(): void
    {
        $queue = new FakeQueueManager();
        $this->bindImportExportServices($queue);
        $this->seedSourceFile('imports/content.ndjson');

        $import = new ImportCreateCommand($this->appContext()->getContainer(), $this->appContext());
        $import->setName('import:run');
        $importResult = (new CommandTester($import))->execute([
            '--adapter' => 'fake',
            '--path' => 'imports/content.ndjson',
        ]);

        $export = new ExportCreateCommand($this->appContext()->getContainer(), $this->appContext());
        $export->setName('export:run');
        $exportResult = (new CommandTester($export))->execute(['--adapter' => 'fake']);

        self::assertSame(Command::SUCCESS, $importResult);
        self::assertSame(Command::SUCCESS, $exportResult);
        self::assertCount(2, $queue->pushed);
    }

    public function testListStatusCancelAndCleanupCommands(): void
    {
        $queue = new FakeQueueManager();
        $dispatcher = new ImportExportCommandsRecordingDispatcher();
        $this->bindImportExportServices($queue);
        $this->bind(EventService::class, new EventService($dispatcher, new ListenerProvider()));
        $job = $this->seedJob(['type' => 'import', 'adapter' => 'fake', 'status' => 'queued']);
        $this->seedBatch(['job_uuid' => $job['uuid']]);

        foreach ([
            'import:list' => new ImportListCommand($this->appContext()->getContainer(), $this->appContext()),
            'export:list' => new ExportListCommand($this->appContext()->getContainer(), $this->appContext()),
            'import-export:status' => new ImportExportStatusCommand($this->appContext()->getContainer(), $this->appContext()),
            'import-export:cancel' => new ImportExportCancelCommand($this->appContext()->getContainer(), $this->appContext()),
            'import-export:cleanup' => new ImportExportCleanupCommand($this->appContext()->getContainer(), $this->appContext()),
        ] as $name => $command) {
            $command->setName($name);
            $args = str_contains($name, 'status') || str_contains($name, 'cancel')
                ? ['job' => $job['uuid']]
                : [];

            self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute($args));
        }

        self::assertSame('cancelled', (new ImportExportJobRepository($this->connection()))->find($job['uuid'])['status']);
        self::assertInstanceOf(ImportExportJobCancelled::class, $dispatcher->events[0] ?? null);
    }

    public function testRetryCommandReturnsFailureWhenRetryIsRefused(): void
    {
        $this->bindImportExportServices(new FakeQueueManager());
        $command = new ImportExportRetryCommand($this->appContext()->getContainer(), $this->appContext());
        $command->setName('import-export:retry');

        $result = (new CommandTester($command))->execute(['job' => 'missing-job']);

        self::assertSame(Command::FAILURE, $result);
    }

    public function testFailedRecordsCommandExportsErrors(): void
    {
        $this->bindImportExportServices(new FakeQueueManager());
        $job = $this->seedJob(['status' => 'failed']);
        $errors = new \Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository(
            $this->connection(),
            new ImportExportJobRepository($this->connection())
        );
        $errors->record($job['uuid'], null, ['message' => 'Bad row', 'code' => 'bad_row']);
        $path = tempnam(sys_get_temp_dir(), 'failed-record-cli-') . '.ndjson';
        $command = new ImportExportFailedRecordsCommand($this->appContext()->getContainer(), $this->appContext());
        $command->setName('import-export:failed-records');

        $result = (new CommandTester($command))->execute([
            'job' => $job['uuid'],
            'path' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $result);
        self::assertStringContainsString('Bad row', (string) file_get_contents($path));

        @unlink($path);
    }

    private function bindImportExportServices(FakeQueueManager $queue): void
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $batches = new ImportExportBatchRepository($this->connection());
        $files = new ImportExportFileRepository($this->connection());
        $importers = new ImporterRegistry([
            new FakeImporter('fake', new ImportPlan(10, [
                new ImportBatch('import-batch-1', 'job-1', 1, 0, 10),
            ], retryable: true)),
        ]);
        $exporters = new ExporterRegistry([
            new FakeExporter('fake', new ExportPlan(10, [
                new ExportBatch('export-batch-1', 'job-1', 1, 0, 10),
            ], retryable: true)),
        ]);

        $this->bind(ImportExportJobRepository::class, $jobs);
        $this->bind(ImportExportBatchRepository::class, $batches);
        $this->bind(
            FailedRecordExporter::class,
            new FailedRecordExporter(new \Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository(
                $this->connection(),
                $jobs
            ))
        );
        $this->bind(ImportExportService::class, new ImportExportService(
            $this->appContext(),
            $importers,
            $exporters,
            $jobs,
            $batches,
            $files,
            $queue
        ));
        $this->bind(RetryService::class, new RetryService($importers, $exporters, $jobs, $batches, $queue));
        $this->bind(RetentionCleaner::class, new RetentionCleaner($this->connection()));
    }
}

final class ImportExportCommandsRecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
