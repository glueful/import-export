<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ImportExportFileRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): array
    {
        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'size_bytes' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);

        $this->connection->table('import_export_files')->insert($row);

        return $row;
    }
}
