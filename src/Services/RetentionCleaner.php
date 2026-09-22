<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Database\Connection;
use Glueful\Storage\StorageManager;
use Psr\Log\LoggerInterface;

/**
 * Removes finished jobs older than a cutoff: the files the job produced, then its rows.
 *
 * Only generated files are deleted — export results and temporary files, through the disk each
 * row names. An import's source is the operator's own file and stays where it is. A job whose
 * files cannot be deleted keeps its rows, so the next run can try again instead of losing track
 * of the files.
 */
final class RetentionCleaner
{
    private const GENERATED_ROLES = ['result', 'tmp'];

    public function __construct(
        private Connection $connection,
        private StorageManager $storage,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** @return int the number of files deleted */
    public function cleanOlderThan(string $cutoff): int
    {
        $jobs = $this->connection
            ->table('import_export_jobs')
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->get();

        $deleted = 0;
        foreach ($jobs as $job) {
            $uuid = (string) $job['uuid'];
            $files = $this->connection
                ->table('import_export_files')
                ->where('job_uuid', '=', $uuid)
                ->whereIn('role', self::GENERATED_ROLES)
                ->get();

            try {
                foreach ($files as $file) {
                    $disk = $this->storage->disk((string) $file['disk']);
                    $path = (string) $file['path'];
                    if ($path !== '' && $disk->fileExists($path)) {
                        $disk->delete($path);
                        $deleted++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Import/export job files could not be deleted; the job is kept.', [
                    'job_uuid' => $uuid,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $tables = ['import_export_files', 'import_export_reports', 'import_export_errors', 'import_export_batches'];
            foreach ($tables as $table) {
                $this->connection->table($table)->where('job_uuid', '=', $uuid)->delete();
            }

            $this->connection
                ->table('import_export_jobs')
                ->where('uuid', '=', $uuid)
                ->delete();
        }

        return $deleted;
    }
}
