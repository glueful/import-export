<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Integration;

use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class MigrationsTest extends ImportExportTestCase
{
    public function testImportExportTablesExist(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        $this->assertTrue($schema->hasTable('import_export_jobs'));
        $this->assertTrue($schema->hasTable('import_export_batches'));
        $this->assertTrue($schema->hasTable('import_export_files'));
        $this->assertTrue($schema->hasTable('import_export_errors'));
        $this->assertTrue($schema->hasTable('import_export_reports'));
    }
}
