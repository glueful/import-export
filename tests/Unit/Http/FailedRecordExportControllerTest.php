<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Extensions\ImportExport\Http\Controllers\FailedRecordExportController;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Symfony\Component\HttpFoundation\Request;

final class FailedRecordExportControllerTest extends ImportExportTestCase
{
    public function testExportWritesFailedRecordsAndReturnsPath(): void
    {
        $job = $this->seedJob(['status' => 'failed']);
        $errors = new ImportExportErrorRepository($this->connection(), new ImportExportJobRepository($this->connection()));
        $errors->record($job['uuid'], null, ['message' => 'Bad row', 'code' => 'bad_row']);
        $path = tempnam(sys_get_temp_dir(), 'failed-record-http-') . '.ndjson';

        $response = $this->controller($errors)->export($this->jsonRequest([
            'path' => $path,
            'format' => 'ndjson',
        ]), $job['uuid']);

        $data = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($path, $data['data']['path']);
        self::assertStringContainsString('Bad row', (string) file_get_contents($path));

        @unlink($path);
    }

    public function testMissingJobReturnsNotFound(): void
    {
        $response = $this->controller()->export($this->jsonRequest(['path' => '/tmp/missing.ndjson']), 'missing-job');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testMissingPathReturnsValidationError(): void
    {
        $job = $this->seedJob(['status' => 'failed']);

        $response = $this->controller()->export($this->jsonRequest([]), $job['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    private function controller(?ImportExportErrorRepository $errors = null): FailedRecordExportController
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $errors ??= new ImportExportErrorRepository($this->connection(), $jobs);

        return new FailedRecordExportController($jobs, new FailedRecordExporter($errors));
    }

    /** @param array<string,mixed> $payload */
    private function jsonRequest(array $payload): Request
    {
        return Request::create(
            '/',
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
