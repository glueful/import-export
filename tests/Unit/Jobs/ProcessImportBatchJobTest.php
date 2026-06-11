<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Jobs;

use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Services\BatchRunner;
use PHPUnit\Framework\TestCase;

final class ProcessImportBatchJobTest extends TestCase
{
    public function testHandleDoesNotThrowWhenRunnerFails(): void
    {
        $runner = $this->createMock(BatchRunner::class);
        $runner->method('runImportBatch')->willThrowException(new \RuntimeException('adapter failed'));

        $job = new ProcessImportBatchJob(['batch_uuid' => 'batch1'], null, $runner);
        $job->handle();

        $this->addToAssertionCount(1);
    }
}
