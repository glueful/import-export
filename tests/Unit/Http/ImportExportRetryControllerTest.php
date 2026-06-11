<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Extensions\ImportExport\Tests\Support\FakeImporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;

final class ImportExportRetryControllerTest extends ImportExportTestCase
{
    public function testMissingJobReturnsNotFound(): void
    {
        $response = $this->controller()->retry('missing-job');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonRetryableAdapterReturnsValidationError(): void
    {
        $job = $this->seedJob(['status' => 'failed', 'adapter' => 'fake']);
        $this->seedBatch(['job_uuid' => $job['uuid'], 'status' => 'failed']);

        $response = $this->controller()->retry($job['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    private function controller(): ImportExportRetryController
    {
        return new ImportExportRetryController(new RetryService(
            new ImporterRegistry([new FakeImporter('fake')]),
            new ExporterRegistry([]),
            new ImportExportJobRepository($this->connection()),
            new ImportExportBatchRepository($this->connection()),
            new FakeQueueManager(),
        ));
    }
}
