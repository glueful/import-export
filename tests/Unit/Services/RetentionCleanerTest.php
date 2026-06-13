<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Services\RetentionCleaner;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class RetentionCleanerTest extends ImportExportTestCase
{
    public function testRetentionCleanerRemovesOldTerminalJobFilesAndKeepsRunningJobs(): void
    {
        $oldFile = tempnam(sys_get_temp_dir(), 'import-export-old-');
        $runningFile = tempnam(sys_get_temp_dir(), 'import-export-running-');
        self::assertIsString($oldFile);
        self::assertIsString($runningFile);
        $oldJob = $this->seedJob(['status' => 'completed', 'created_at' => '2026-01-01 00:00:00']);
        $runningJob = $this->seedJob(['uuid' => 'runningjob1', 'status' => 'running', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'oldfile00001',
            'job_uuid' => $oldJob['uuid'],
            'role' => 'tmp',
            'disk' => 'local',
            'path' => $oldFile,
            'size_bytes' => 0,
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'runfile00001',
            'job_uuid' => $runningJob['uuid'],
            'role' => 'tmp',
            'disk' => 'local',
            'path' => $runningFile,
            'size_bytes' => 0,
        ]);

        $deleted = (new RetentionCleaner($this->connection()))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($runningFile);
        $this->assertNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $oldJob['uuid'])->first());
        $this->assertNotNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $runningJob['uuid'])->first());
        $this->assertSame([], $this->connection()->table('import_export_files')->where('job_uuid', '=', $oldJob['uuid'])->get());
    }

    public function testRetentionCleanerPrunesOldTerminalJobRelatedRows(): void
    {
        $oldJob = $this->seedJob(['status' => 'failed', 'created_at' => '2026-01-01 00:00:00']);
        $batch = $this->seedBatch(['job_uuid' => $oldJob['uuid'], 'status' => 'failed']);
        $this->connection()->table('import_export_errors')->insert([
            'uuid' => 'error0000001',
            'job_uuid' => $oldJob['uuid'],
            'batch_uuid' => $batch['uuid'],
            'severity' => 'error',
            'code' => 'bad_row',
            'message' => 'Bad row',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection()->table('import_export_reports')->insert([
            'uuid' => 'report000001',
            'job_uuid' => $oldJob['uuid'],
            'summary' => '{}',
            'created_at' => '2026-01-01 00:00:00',
        ]);

        (new RetentionCleaner($this->connection()))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $oldJob['uuid'])->first());
        $this->assertSame([], $this->connection()->table('import_export_batches')->where('job_uuid', '=', $oldJob['uuid'])->get());
        $this->assertSame([], $this->connection()->table('import_export_errors')->where('job_uuid', '=', $oldJob['uuid'])->get());
        $this->assertSame([], $this->connection()->table('import_export_reports')->where('job_uuid', '=', $oldJob['uuid'])->get());
    }
}
