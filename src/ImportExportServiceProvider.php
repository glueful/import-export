<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Console\ImportExportRetryCommand;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Http\RequireImportExportPermission;
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
            ImporterRegistry::class => new FactoryDefinition(
                ImporterRegistry::class,
                static function (ContainerInterface $c): ImporterRegistry {
                    $importers = $c->has('import_export.importer')
                        ? $c->get('import_export.importer')
                        : [];

                    if ($importers instanceof \Traversable) {
                        $importers = iterator_to_array($importers);
                    }

                    return new ImporterRegistry((array) $importers);
                }
            ),
            ExporterRegistry::class => new FactoryDefinition(
                ExporterRegistry::class,
                static function (ContainerInterface $c): ExporterRegistry {
                    $exporters = $c->has('import_export.exporter')
                        ? $c->get('import_export.exporter')
                        : [];

                    if ($exporters instanceof \Traversable) {
                        $exporters = iterator_to_array($exporters);
                    }

                    return new ExporterRegistry((array) $exporters);
                }
            ),
            ImportExportJobRepository::class => ['class' => ImportExportJobRepository::class, 'shared' => true, 'autowire' => true],
            ImportExportBatchRepository::class => ['class' => ImportExportBatchRepository::class, 'shared' => true, 'autowire' => true],
            ImportExportFileRepository::class => ['class' => ImportExportFileRepository::class, 'shared' => true, 'autowire' => true],
            ImportExportReportRepository::class => ['class' => ImportExportReportRepository::class, 'shared' => true, 'autowire' => true],
            ImportExportErrorRepository::class => ['class' => ImportExportErrorRepository::class, 'shared' => true, 'autowire' => true],
            ImportExportService::class => new FactoryDefinition(
                ImportExportService::class,
                static function (ContainerInterface $c): ImportExportService {
                    $context = $c->get(ApplicationContext::class);

                    return new ImportExportService(
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
            ),
            BatchRunner::class => ['class' => BatchRunner::class, 'shared' => true, 'autowire' => true],
            RetryService::class => new FactoryDefinition(
                RetryService::class,
                static function (ContainerInterface $c): RetryService {
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
            ),
            ReportBuilder::class => ['class' => ReportBuilder::class, 'shared' => true, 'autowire' => true],
            FailedRecordExporter::class => ['class' => FailedRecordExporter::class, 'shared' => true, 'autowire' => true],
            RetentionCleaner::class => ['class' => RetentionCleaner::class, 'shared' => true, 'autowire' => true],
            RequireImportExportPermission::class => [
                'class' => RequireImportExportPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['import_export_permission'],
            ],
            ImportExportRetryController::class => ['class' => ImportExportRetryController::class, 'shared' => true, 'autowire' => true],
            ImportExportRetryCommand::class => ['class' => ImportExportRetryCommand::class, 'shared' => true, 'autowire' => true],
        ];
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
        $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
    }

    public function permissions(): array
    {
        return [
            Permission::define('import_export.view')->label('View import/export jobs')->category('Import Export')->resource('import_export')->managedBy('glueful/import-export'),
            Permission::define('import_export.run_import')->label('Run imports')->category('Import Export')->resource('import_export')->managedBy('glueful/import-export'),
            Permission::define('import_export.run_export')->label('Run exports')->category('Import Export')->resource('import_export')->managedBy('glueful/import-export'),
            Permission::define('import_export.cancel')->label('Cancel import/export jobs')->category('Import Export')->resource('import_export')->managedBy('glueful/import-export'),
            Permission::define('import_export.retry')->label('Retry import/export jobs')->category('Import Export')->resource('import_export')->managedBy('glueful/import-export'),
        ];
    }
}
