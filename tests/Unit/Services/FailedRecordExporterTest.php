<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class FailedRecordExporterTest extends ImportExportTestCase
{
    public function testExportsFailedRowsAsNdjson(): void
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $errors = new ImportExportErrorRepository($this->connection(), $jobs);
        $job = $this->seedJob();
        $errors->record($job['uuid'], null, [
            'severity' => 'error',
            'code' => 'invalid',
            'message' => 'Invalid row',
            'record_number' => 12,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'failed-records-') . '.ndjson';

        (new FailedRecordExporter($errors))->export($job['uuid'], $path, 'ndjson');

        $this->assertStringContainsString('"record_number":12', (string) file_get_contents($path));
    }
}
