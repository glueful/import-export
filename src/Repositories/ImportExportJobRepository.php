<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ImportExportJobRepository
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'pending' => ['planning', 'queued', 'cancelled', 'failed'],
        'planning' => ['queued', 'running', 'cancelled', 'failed'],
        'queued' => ['running', 'cancelled', 'failed'],
        'running' => ['completed', 'failed', 'cancelled'],
        'failed' => ['queued'],
        'completed' => [],
        'cancelled' => [],
    ];

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
            'mode' => 'dry_run',
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
            'error_overflow_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data);

        $this->connection->table('import_export_jobs')->insert($row);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->connection
            ->table('import_export_jobs')
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $type = null, ?string $status = null, int $limit = 50): array
    {
        $query = $this->connection
            ->table('import_export_jobs')
            ->orderBy('id', 'DESC')
            ->limit($limit);

        if ($type !== null && $type !== '') {
            $query->where('type', '=', $type);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }

        return $query->get();
    }

    public function transition(string $uuid, string $toStatus): void
    {
        $job = $this->find($uuid);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Import/export job "%s" was not found.', $uuid));
        }

        $fromStatus = (string) $job['status'];
        if (!in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid job transition from "%s" to "%s".',
                $fromStatus,
                $toStatus
            ));
        }

        $updates = [
            'status' => $toStatus,
            'updated_at' => $this->now(),
        ];

        if ($toStatus === 'running' && ($job['started_at'] ?? null) === null) {
            $updates['started_at'] = $this->now();
        }

        if (in_array($toStatus, ['completed', 'failed', 'cancelled'], true)) {
            $updates['finished_at'] = $this->now();
        }

        $this->connection
            ->table('import_export_jobs')
            ->where('uuid', '=', $uuid)
            ->update($updates);
    }

    public function cancel(string $uuid): void
    {
        $this->transition($uuid, 'cancelled');
    }

    public function updateProgress(string $uuid, int $processedRecords, int $failedRecords): void
    {
        $this->connection
            ->table('import_export_jobs')
            ->where('uuid', '=', $uuid)
            ->update([
                'processed_records' => $processedRecords,
                'failed_records' => $failedRecords,
                'updated_at' => $this->now(),
            ]);
    }

    public function incrementErrorOverflow(string $uuid, int $by = 1): void
    {
        $this->connection->table('import_export_jobs')->executeModification(
            'UPDATE import_export_jobs SET error_overflow_count = error_overflow_count + ?, updated_at = ? WHERE uuid = ?',
            [$by, $this->now(), $uuid]
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
