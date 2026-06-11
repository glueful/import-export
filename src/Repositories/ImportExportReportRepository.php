<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ImportExportReportRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): array
    {
        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);

        if (isset($row['summary']) && is_array($row['summary'])) {
            $row['summary'] = json_encode($row['summary'], JSON_THROW_ON_ERROR);
        }

        $this->connection->table('import_export_reports')->insert($row);

        return $row;
    }
}
