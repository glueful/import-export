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

        $response = $this->controller($errors)->export($this->jsonRequest([
            'format' => 'ndjson',
        ]), $job['uuid']);

        $data = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('local', $data['data']['disk']);
        self::assertSame("failed-records/{$job['uuid']}.ndjson", $data['data']['path']);

        $managedPath = sys_get_temp_dir() . "/import-export/failed-records/{$job['uuid']}.ndjson";
        self::assertStringContainsString('Bad row', (string) file_get_contents($managedPath));
        @unlink($managedPath);
    }

    public function testMissingJobReturnsNotFound(): void
    {
        $response = $this->controller()->export($this->jsonRequest([]), 'missing-job');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRequestPathIsNotUsedForHttpExport(): void
    {
        $job = $this->seedJob(['status' => 'failed']);
        $attackerPath = tempnam(sys_get_temp_dir(), 'failed-record-attacker-') . '.ndjson';
        @unlink($attackerPath);

        $response = $this->controller()->export($this->jsonRequest([
            'path' => $attackerPath,
            'format' => 'ndjson',
        ]), $job['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFileDoesNotExist($attackerPath);
    }

    private function controller(?ImportExportErrorRepository $errors = null): FailedRecordExportController
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $errors ??= new ImportExportErrorRepository($this->connection(), $jobs);

        return new FailedRecordExportController($this->appContext(), $jobs, new FailedRecordExporter($errors));
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
