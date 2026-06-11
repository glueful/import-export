<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Extensions\ImportExport\Http\Controllers\ExportJobController;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportPlan;
use Glueful\Extensions\ImportExport\Tests\Support\FakeExporter;
use Glueful\Extensions\ImportExport\Tests\Support\FakeQueueManager;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExportJobControllerTest extends ImportExportTestCase
{
    public function testCreateExportQueuesBatches(): void
    {
        $queue = new FakeQueueManager();
        $jobs = new ImportExportJobRepository($this->connection());
        $controller = new ExportJobController(new ImportExportService(
            new ImporterRegistry([]),
            new ExporterRegistry([
                new FakeExporter('fake', new ExportPlan(10, [
                    new ExportBatch('batch-1', 'job-1', 1, 0, 10),
                ], retryable: true)),
            ]),
            $jobs,
            new ImportExportBatchRepository($this->connection()),
            new ImportExportFileRepository($this->connection()),
            $queue
        ));

        $response = $controller->store($this->jsonRequest('/import-export/exports', ['adapter' => 'fake']));
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('export', $data['data']['job']['type']);
        self::assertCount(1, $queue->pushed);
    }

    /** @param array<string,mixed> $payload */
    private function jsonRequest(string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
