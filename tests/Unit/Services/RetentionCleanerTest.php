<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Services;

use Glueful\Extensions\ImportExport\Services\RetentionCleaner;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;

final class RetentionCleanerTest extends ImportExportTestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testFinishedExportResultsAreDeletedThroughTheirDiskAndRunningJobsAreKept(): void
    {
        $storage = $this->storage();
        $oldJob = $this->seedJob(['status' => 'completed', 'created_at' => '2026-01-01 00:00:00']);
        $runningJob = $this->seedJob(['uuid' => 'runningjob1', 'status' => 'running', 'created_at' => '2026-01-01 00:00:00']);
        $this->seedFile('oldresult001', $oldJob['uuid'], 'result', 'exports/old/part-0001.ndjson');
        $this->seedFile('oldtmp000001', $oldJob['uuid'], 'tmp', 'tmp/old.part');
        $this->seedFile('runresult001', $runningJob['uuid'], 'result', 'exports/running/part-0001.ndjson');

        $deleted = (new RetentionCleaner($this->connection(), $storage))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($this->root . '/exports/old/part-0001.ndjson');
        $this->assertFileDoesNotExist($this->root . '/tmp/old.part');
        $this->assertFileExists($this->root . '/exports/running/part-0001.ndjson');
        $this->assertNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $oldJob['uuid'])->first());
        $this->assertNotNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $runningJob['uuid'])->first());
        $this->assertSame([], $this->connection()->table('import_export_files')->where('job_uuid', '=', $oldJob['uuid'])->get());
    }

    public function testAnImportSourceIsLeftWhereTheOperatorPutIt(): void
    {
        $storage = $this->storage();
        $job = $this->seedJob(['status' => 'completed', 'created_at' => '2026-01-01 00:00:00']);
        $this->seedFile('source000001', $job['uuid'], 'source', 'incoming/products.csv');

        $deleted = (new RetentionCleaner($this->connection(), $storage))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertSame(0, $deleted);
        $this->assertFileExists($this->root . '/incoming/products.csv');
        $this->assertNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $job['uuid'])->first());
    }

    public function testAJobWhoseFileCannotBeDeletedKeepsItsRowsForTheNextRun(): void
    {
        $storage = $this->storage();
        $job = $this->seedJob(['status' => 'completed', 'created_at' => '2026-01-01 00:00:00']);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'lostdisk0001',
            'job_uuid' => $job['uuid'],
            'role' => 'result',
            'disk' => 'unconfigured',
            'path' => 'exports/part-0001.ndjson',
            'size_bytes' => 0,
        ]);

        $deleted = (new RetentionCleaner($this->connection(), $storage))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertSame(0, $deleted);
        $this->assertNotNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $job['uuid'])->first());
        $this->assertCount(1, $this->connection()->table('import_export_files')->where('job_uuid', '=', $job['uuid'])->get());
    }

    private function storage(): StorageManager
    {
        $this->root = sys_get_temp_dir() . '/import-export-retention-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);

        return new StorageManager(
            ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $this->root]]],
            new PathGuard(),
        );
    }

    private function seedFile(string $uuid, string $jobUuid, string $role, string $path): void
    {
        $absolute = $this->root . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, "{}\n");
        $this->connection()->table('import_export_files')->insert([
            'uuid' => $uuid,
            'job_uuid' => $jobUuid,
            'role' => $role,
            'disk' => 'local',
            'path' => $path,
            'size_bytes' => 3,
        ]);
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

        (new RetentionCleaner($this->connection(), $this->storage()))->cleanOlderThan('2026-02-01 00:00:00');

        $this->assertNull($this->connection()->table('import_export_jobs')->where('uuid', '=', $oldJob['uuid'])->first());
        $this->assertSame([], $this->connection()->table('import_export_batches')->where('job_uuid', '=', $oldJob['uuid'])->get());
        $this->assertSame([], $this->connection()->table('import_export_errors')->where('job_uuid', '=', $oldJob['uuid'])->get());
        $this->assertSame([], $this->connection()->table('import_export_reports')->where('job_uuid', '=', $oldJob['uuid'])->get());
    }
}
