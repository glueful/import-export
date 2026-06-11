<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ImportExportErrorRepository
{
    public function __construct(
        private Connection $connection,
        private ImportExportJobRepository $jobs,
    ) {
    }

    /**
     * @param array<string,mixed> $error
     */
    public function record(string $jobUuid, ?string $batchUuid, array $error, int $capPerSeverity = 1000): void
    {
        $severity = (string) ($error['severity'] ?? 'error');
        if ($this->countForSeverity($jobUuid, $severity) >= $capPerSeverity) {
            $this->jobs->incrementErrorOverflow($jobUuid);
            return;
        }

        $this->connection->table('import_export_errors')->insert([
            'uuid' => Utils::generateNanoID(12),
            'job_uuid' => $jobUuid,
            'batch_uuid' => $batchUuid,
            'record_number' => $error['record_number'] ?? null,
            'severity' => $severity,
            'code' => (string) ($error['code'] ?? 'error'),
            'message' => (string) ($error['message'] ?? 'Import/export error'),
            'context' => isset($error['context']) ? json_encode($error['context'], JSON_THROW_ON_ERROR) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function forJob(string $jobUuid): array
    {
        return $this->connection
            ->table('import_export_errors')
            ->where('job_uuid', '=', $jobUuid)
            ->orderBy('id')
            ->get();
    }

    private function countForSeverity(string $jobUuid, string $severity): int
    {
        $row = $this->connection
            ->table('import_export_errors')
            ->selectRaw('COUNT(*) AS count')
            ->where('job_uuid', '=', $jobUuid)
            ->where('severity', '=', $severity)
            ->first();

        return (int) ($row['count'] ?? 0);
    }
}
