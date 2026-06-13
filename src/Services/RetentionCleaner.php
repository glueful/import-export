<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Database\Connection;

final class RetentionCleaner
{
    public function __construct(private Connection $connection)
    {
    }

    public function cleanOlderThan(string $cutoff): int
    {
        $jobs = $this->connection
            ->table('import_export_jobs')
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->get();

        $deleted = 0;
        foreach ($jobs as $job) {
            $files = $this->connection
                ->table('import_export_files')
                ->where('job_uuid', '=', $job['uuid'])
                ->where('role', '=', 'tmp')
                ->get();

            foreach ($files as $file) {
                $path = (string) $file['path'];
                if (is_file($path) && unlink($path)) {
                    $deleted++;
                }
            }

            $this->connection
                ->table('import_export_files')
                ->where('job_uuid', '=', $job['uuid'])
                ->delete();

            $this->connection
                ->table('import_export_reports')
                ->where('job_uuid', '=', $job['uuid'])
                ->delete();

            $this->connection
                ->table('import_export_errors')
                ->where('job_uuid', '=', $job['uuid'])
                ->delete();

            $this->connection
                ->table('import_export_batches')
                ->where('job_uuid', '=', $job['uuid'])
                ->delete();

            $this->connection
                ->table('import_export_jobs')
                ->where('uuid', '=', $job['uuid'])
                ->delete();
        }

        return $deleted;
    }
}
