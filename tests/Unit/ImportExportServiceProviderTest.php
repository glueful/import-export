<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit;

use Glueful\Extensions\ImportExport\ImportExportServiceProvider;
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
        self::assertArrayHasKey('alias', $services[\Glueful\Extensions\ImportExport\Http\RequireImportExportPermission::class]);
        self::assertContains('import_export_permission', $services[\Glueful\Extensions\ImportExport\Http\RequireImportExportPermission::class]['alias']);
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
