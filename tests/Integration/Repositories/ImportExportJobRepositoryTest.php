<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Integration\Repositories;

use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ImportExportJobRepositoryTest extends ImportExportTestCase
{
    public function testCreatesAndFindsJob(): void
    {
        $repository = new ImportExportJobRepository($this->connection());

        $job = $repository->create([
            'type' => 'import',
            'adapter' => 'wordpress',
            'status' => 'pending',
            'mode' => 'dry_run',
            'created_by' => 'user123',
        ]);

        $found = $repository->find($job['uuid']);

        $this->assertSame('wordpress', $found['adapter']);
        $this->assertSame('pending', $found['status']);
        $this->assertSame('dry_run', $found['mode']);
    }

    public function testValidLifecycleTransitionUpdatesStatus(): void
    {
        $repository = new ImportExportJobRepository($this->connection());
        $job = $this->seedJob(['status' => 'pending']);

        $repository->transition($job['uuid'], 'planning');

        $this->assertSame('planning', $repository->find($job['uuid'])['status']);
    }

    public function testInvalidLifecycleTransitionThrows(): void
    {
        $repository = new ImportExportJobRepository($this->connection());
        $job = $this->seedJob(['status' => 'completed']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid job transition');

        $repository->transition($job['uuid'], 'running');
    }

    public function testIncrementsErrorOverflowCount(): void
    {
        $repository = new ImportExportJobRepository($this->connection());
        $job = $this->seedJob(['error_overflow_count' => 0]);

        $repository->incrementErrorOverflow($job['uuid'], 3);

        $this->assertSame(3, (int) $repository->find($job['uuid'])['error_overflow_count']);
    }
}
