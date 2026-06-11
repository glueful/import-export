<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;

final class ReportBuilder
{
    public function __construct(
        private ImportExportJobRepository $jobs,
        private ImportExportErrorRepository $errors,
        private ImportExportReportRepository $reports,
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $jobUuid): array
    {
        $job = $this->jobs->find($jobUuid);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Import/export job "%s" was not found.', $jobUuid));
        }

        $storedErrors = $this->errors->forJob($jobUuid);

        return $this->reports->create([
            'job_uuid' => $jobUuid,
            'summary' => [
                'type' => $job['type'],
                'adapter' => $job['adapter'],
                'status' => $job['status'],
                'total_records' => (int) $job['total_records'],
                'processed_records' => (int) $job['processed_records'],
                'failed_records' => (int) $job['failed_records'],
                'error_overflow_count' => (int) $job['error_overflow_count'],
                'stored_errors' => count($storedErrors),
            ],
        ]);
    }
}
