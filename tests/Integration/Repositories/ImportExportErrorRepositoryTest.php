<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Integration\Repositories;

use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ImportExportErrorRepositoryTest extends ImportExportTestCase
{
    public function testErrorCapRecordsOverflowInsteadOfUnboundedRows(): void
    {
        $jobRepository = new ImportExportJobRepository($this->connection());
        $repository = new ImportExportErrorRepository($this->connection(), $jobRepository);
        $job = $this->seedJob();

        for ($i = 1; $i <= 3; $i++) {
            $repository->record($job['uuid'], null, [
                'severity' => 'error',
                'code' => 'invalid',
                'message' => 'Invalid row',
                'record_number' => $i,
            ], capPerSeverity: 2);
        }

        $errors = $repository->forJob($job['uuid']);
        $updatedJob = $jobRepository->find($job['uuid']);

        $this->assertCount(2, $errors);
        $this->assertSame(1, (int) $updatedJob['error_overflow_count']);
    }
}
