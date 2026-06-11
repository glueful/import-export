<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ReportBuilderTest extends ImportExportTestCase
{
    public function testBuildStoresSummaryIncludingOverflow(): void
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $errors = new ImportExportErrorRepository($this->connection(), $jobs);
        $reports = new ImportExportReportRepository($this->connection());
        $job = $this->seedJob([
            'total_records' => 10,
            'processed_records' => 8,
            'failed_records' => 2,
            'error_overflow_count' => 3,
        ]);
        $errors->record($job['uuid'], null, ['severity' => 'error', 'code' => 'bad', 'message' => 'Bad row']);

        $report = (new ReportBuilder($jobs, $errors, $reports))->build($job['uuid']);

        $summary = json_decode((string) $report['summary'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(10, $summary['total_records']);
        $this->assertSame(2, $summary['failed_records']);
        $this->assertSame(3, $summary['error_overflow_count']);
        $this->assertSame(1, $summary['stored_errors']);
    }
}
