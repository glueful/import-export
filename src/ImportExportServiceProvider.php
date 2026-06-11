<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Console\ExportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ExportListCommand;
use Glueful\Extensions\ImportExport\Console\ImportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCancelCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCleanupCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportFailedRecordsCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportRetryCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportStatusCommand;
use Glueful\Extensions\ImportExport\Console\ImportListCommand;
use Glueful\Extensions\ImportExport\Http\Controllers\ExportJobController;
use Glueful\Extensions\ImportExport\Http\Controllers\FailedRecordExportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportAdapterController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportJobController;
use Glueful\Extensions\ImportExport\Http\RequireImportExportPermission;
use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Extensions\ImportExport\Services\RetentionCleaner;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\Permission;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;

final class ImportExportServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            self::$cachedVersion = (string) ($composer['extra']['glueful']['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    /** @return array<string,mixed> */
    public static function services(): array
    {
        return [
            ImporterRegistry::class => [
                'factory' => [self::class, 'makeImporterRegistry'],
                'shared' => true,
            ],
            ExporterRegistry::class => [
                'factory' => [self::class, 'makeExporterRegistry'],
                'shared' => true,
            ],
            ImportExportJobRepository::class => self::autowired(ImportExportJobRepository::class),
            ImportExportBatchRepository::class => self::autowired(ImportExportBatchRepository::class),
            ImportExportFileRepository::class => self::autowired(ImportExportFileRepository::class),
            ImportExportReportRepository::class => self::autowired(ImportExportReportRepository::class),
            ImportExportErrorRepository::class => self::autowired(ImportExportErrorRepository::class),
            ImportExportService::class => [
                'factory' => [self::class, 'makeImportExportService'],
                'shared' => true,
            ],
            BatchRunner::class => self::autowired(BatchRunner::class),
            RetryService::class => [
                'factory' => [self::class, 'makeRetryService'],
                'shared' => true,
            ],
            ReportBuilder::class => self::autowired(ReportBuilder::class),
            FailedRecordExporter::class => self::autowired(FailedRecordExporter::class),
            RetentionCleaner::class => self::autowired(RetentionCleaner::class),
            ProcessImportBatchJob::class => self::autowired(ProcessImportBatchJob::class, shared: false),
            ProcessExportBatchJob::class => self::autowired(ProcessExportBatchJob::class, shared: false),
            RequireImportExportPermission::class => [
                'class' => RequireImportExportPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['import_export_permission'],
            ],
            ImportExportAdapterController::class => self::autowired(ImportExportAdapterController::class),
            ImportJobController::class => self::autowired(ImportJobController::class),
            ExportJobController::class => self::autowired(ExportJobController::class),
            FailedRecordExportController::class => self::autowired(FailedRecordExportController::class),
            ImportExportReportController::class => self::autowired(ImportExportReportController::class),
            ImportExportRetryController::class => self::autowired(ImportExportRetryController::class),
            ImportListCommand::class => self::autowired(ImportListCommand::class),
            ImportCreateCommand::class => self::autowired(ImportCreateCommand::class),
            ExportListCommand::class => self::autowired(ExportListCommand::class),
            ExportCreateCommand::class => self::autowired(ExportCreateCommand::class),
            ImportExportStatusCommand::class => self::autowired(ImportExportStatusCommand::class),
            ImportExportCancelCommand::class => self::autowired(ImportExportCancelCommand::class),
            ImportExportCleanupCommand::class => self::autowired(ImportExportCleanupCommand::class),
            ImportExportFailedRecordsCommand::class => self::autowired(ImportExportFailedRecordsCommand::class),
            ImportExportRetryCommand::class => self::autowired(ImportExportRetryCommand::class),
        ];
    }

    public static function makeImporterRegistry(ContainerInterface $c): ImporterRegistry
    {
        $importers = $c->has('import_export.importer')
            ? $c->get('import_export.importer')
            : [];

        if ($importers instanceof \Traversable) {
            $importers = iterator_to_array($importers);
        }

        return new ImporterRegistry((array) $importers);
    }

    public static function makeExporterRegistry(ContainerInterface $c): ExporterRegistry
    {
        $exporters = $c->has('import_export.exporter')
            ? $c->get('import_export.exporter')
            : [];

        if ($exporters instanceof \Traversable) {
            $exporters = iterator_to_array($exporters);
        }

        return new ExporterRegistry((array) $exporters);
    }

    public static function makeImportExportService(ContainerInterface $c): ImportExportService
    {
        $context = $c->get(ApplicationContext::class);

        return new ImportExportService(
            $context,
            $c->get(ImporterRegistry::class),
            $c->get(ExporterRegistry::class),
            $c->get(ImportExportJobRepository::class),
            $c->get(ImportExportBatchRepository::class),
            $c->get(ImportExportFileRepository::class),
            $c->get(QueueManager::class),
            (string) config($context, 'import_export.queue', 'import-export'),
            $c->has(EventService::class) ? $c->get(EventService::class) : null,
        );
    }

    public static function makeRetryService(ContainerInterface $c): RetryService
    {
        $context = $c->get(ApplicationContext::class);

        return new RetryService(
            $c->get(ImporterRegistry::class),
            $c->get(ExporterRegistry::class),
            $c->get(ImportExportJobRepository::class),
            $c->get(ImportExportBatchRepository::class),
            $c->get(QueueManager::class),
            (string) config($context, 'import_export.queue', 'import-export'),
        );
    }

    /**
     * @param class-string $class
     * @return array{class:class-string,shared:bool,autowire:bool}
     */
    private static function autowired(string $class, bool $shared = true): array
    {
        return ['class' => $class, 'shared' => $shared, 'autowire' => true];
    }

    public function getName(): string
    {
        return 'Import Export';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'Import and export engine for Glueful apps.';
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('import_export', require __DIR__ . '/../config/import_export.php');
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEFAULT,
            'glueful/import-export'
        );
    }

    public function boot(ApplicationContext $context): void
    {
        $this->discoverCommands('Glueful\\Extensions\\ImportExport\\Console', __DIR__ . '/Console');
        if ((bool) config($context, 'import_export.routes_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
        }
    }

    public function permissions(): array
    {
        return [
            Permission::define('import_export.view')
                ->label('View import/export jobs')
                ->category('Import Export')
                ->resource('import_export')
                ->managedBy('glueful/import-export'),
            Permission::define('import_export.run_import')
                ->label('Run imports')
                ->category('Import Export')
                ->resource('import_export')
                ->managedBy('glueful/import-export'),
            Permission::define('import_export.run_export')
                ->label('Run exports')
                ->category('Import Export')
                ->resource('import_export')
                ->managedBy('glueful/import-export'),
            Permission::define('import_export.cancel')
                ->label('Cancel import/export jobs')
                ->category('Import Export')
                ->resource('import_export')
                ->managedBy('glueful/import-export'),
            Permission::define('import_export.retry')
                ->label('Retry import/export jobs')
                ->category('Import Export')
                ->resource('import_export')
                ->managedBy('glueful/import-export'),
        ];
    }
}
