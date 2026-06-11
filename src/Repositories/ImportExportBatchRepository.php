<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ImportExportBatchRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now = $this->now();
        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'status' => 'pending',
            'offset' => 0,
            'limit' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data);

        $this->connection->table('import_export_batches')->insert($row);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->connection
            ->table('import_export_batches')
            ->where('uuid', '=', $uuid)
            ->first();
    }

    public function claim(string $uuid, string $staleBefore): bool
    {
        $lockedAt = $this->now();
        $staleCutoff = $this->normalizeDateTime($staleBefore);

        $affected = $this->connection->table('import_export_batches')->executeModification(
            'UPDATE import_export_batches
                SET status = ?, locked_at = ?, attempts = attempts + 1, started_at = COALESCE(started_at, ?), updated_at = ?
              WHERE uuid = ?
                AND (status = ? OR (status = ? AND locked_at IS NOT NULL AND locked_at < ?))',
            [
                'running',
                $lockedAt,
                $lockedAt,
                $lockedAt,
                $uuid,
                'pending',
                'running',
                $staleCutoff,
            ]
        );

        return $affected === 1;
    }

    public function complete(string $uuid, int $processedRecords, int $failedRecords): void
    {
        $this->connection
            ->table('import_export_batches')
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => $failedRecords > 0 ? 'failed' : 'completed',
                'processed_records' => $processedRecords,
                'failed_records' => $failedRecords,
                'finished_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
    }

    /** @return list<array<string,mixed>> */
    public function failedForJob(string $jobUuid): array
    {
        return $this->connection
            ->table('import_export_batches')
            ->where('job_uuid', '=', $jobUuid)
            ->where('status', '=', 'failed')
            ->orderBy('sequence')
            ->get();
    }

    public function resetForRetry(string $uuid): void
    {
        $this->connection
            ->table('import_export_batches')
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => 'pending',
                'locked_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $this->now(),
            ]);
    }

    private function normalizeDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException(sprintf('Invalid datetime value "%s".', $value));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
