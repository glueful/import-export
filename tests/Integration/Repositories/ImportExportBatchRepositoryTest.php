<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Integration\Repositories;

use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ImportExportBatchRepositoryTest extends ImportExportTestCase
{
    public function testClaimPendingBatchChangesStatusAttemptsAndLockedAt(): void
    {
        $repository = new ImportExportBatchRepository($this->connection());
        $batch = $this->seedBatch(['status' => 'pending', 'attempts' => 0, 'locked_at' => null]);

        $claimed = $repository->claim($batch['uuid'], staleBefore: '-10 minutes');
        $row = $repository->find($batch['uuid']);

        $this->assertTrue($claimed);
        $this->assertSame('running', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNotNull($row['locked_at']);
    }

    public function testAlreadyClaimedFreshBatchCannotBeClaimedAgain(): void
    {
        $repository = new ImportExportBatchRepository($this->connection());
        $batch = $this->seedBatch([
            'status' => 'running',
            'attempts' => 1,
            'locked_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertFalse($repository->claim($batch['uuid'], staleBefore: '-10 minutes'));
    }

    public function testStaleRunningBatchCanBeReclaimedAndLockedAtChanges(): void
    {
        $repository = new ImportExportBatchRepository($this->connection());
        $oldLock = '2026-01-01 00:00:00';
        $batch = $this->seedBatch([
            'status' => 'running',
            'attempts' => 1,
            'locked_at' => $oldLock,
        ]);

        $claimed = $repository->claim($batch['uuid'], staleBefore: '2026-01-02 00:00:00');
        $row = $repository->find($batch['uuid']);

        $this->assertTrue($claimed);
        $this->assertSame('running', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertNotSame($oldLock, $row['locked_at']);
    }
}
