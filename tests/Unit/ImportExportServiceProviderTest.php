<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit;

use Glueful\Extensions\ImportExport\ImportExportServiceProvider;
use Glueful\Extensions\ImportExport\Console\ExportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ExportListCommand;
use Glueful\Extensions\ImportExport\Console\ImportCreateCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCancelCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportCleanupCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportRetryCommand;
use Glueful\Extensions\ImportExport\Console\ImportExportStatusCommand;
use Glueful\Extensions\ImportExport\Console\ImportListCommand;
use Glueful\Extensions\ImportExport\Http\Controllers\ExportJobController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportAdapterController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportJobController;
use Glueful\Extensions\ImportExport\Http\RequireImportExportPermission;
use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Glueful\Permissions\Catalog\Permission;

final class ImportExportServiceProviderTest extends ImportExportTestCase
{
    public function testRegistersCoreServicesAndPermissionGuardAlias(): void
    {
        $services = ImportExportServiceProvider::services();

        self::assertArrayHasKey(ImporterRegistry::class, $services);
        self::assertArrayHasKey(ExporterRegistry::class, $services);
        self::assertArrayHasKey('alias', $services[RequireImportExportPermission::class]);
        self::assertContains('import_export_permission', $services[RequireImportExportPermission::class]['alias']);

        foreach ([
            ProcessImportBatchJob::class,
            ProcessExportBatchJob::class,
            ImportExportAdapterController::class,
            ImportJobController::class,
            ExportJobController::class,
            ImportExportReportController::class,
            ImportExportRetryController::class,
            ImportListCommand::class,
            ImportCreateCommand::class,
            ExportListCommand::class,
            ExportCreateCommand::class,
            ImportExportStatusCommand::class,
            ImportExportCancelCommand::class,
            ImportExportCleanupCommand::class,
            ImportExportRetryCommand::class,
        ] as $serviceId) {
            self::assertArrayHasKey($serviceId, $services);
        }
    }

    public function testProviderDeclaresImportExportPermissions(): void
    {
        $provider = new ImportExportServiceProvider($this->appContext()->getContainer());

        $permissions = array_map(
            static fn(Permission $permission): string => $permission->slug(),
            $provider->permissions()
        );

        self::assertSame([
            'import_export.view',
            'import_export.run_import',
            'import_export.run_export',
            'import_export.cancel',
            'import_export.retry',
        ], $permissions);
    }

    public function testImporterRegistryFactoryAcceptsTaggedImportersWhenPresent(): void
    {
        $importer = new FakeImporter('fake');
        $this->bind('import_export.importer', [$importer]);

        $definition = ImportExportServiceProvider::services()[ImporterRegistry::class];
        $registry = $definition->resolve($this->appContext()->getContainer());

        self::assertSame($importer, $registry->get('fake'));
    }

    public function testExporterRegistryFactoryAcceptsTaggedExportersWhenPresent(): void
    {
        $exporter = new FakeExporter('fake');
        $this->bind('import_export.exporter', [$exporter]);

        $definition = ImportExportServiceProvider::services()[ExporterRegistry::class];
        $registry = $definition->resolve($this->appContext()->getContainer());

        self::assertSame($exporter, $registry->get('fake'));
    }
}
