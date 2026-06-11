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
    }
}
