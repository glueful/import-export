<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/import-export (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class ImportExportSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/import-export';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateImportExportTables.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateImportExportTables.php' => $this->tablesWithColumns($db, [
                'import_export_jobs' => ['adapter'],
                'import_export_batches' => [],
                'import_export_files' => [],
                'import_export_errors' => [],
                'import_export_reports' => [],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
