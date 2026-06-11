<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Extensions\ImportExport\Tests\Support\ImportExportTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportReportControllerTest extends ImportExportTestCase
{
    public function testReportEndpointBuildsReportWhenMissing(): void
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $reports = new ImportExportReportRepository($this->connection());
        $controller = new ImportExportReportController(
            $jobs,
            $reports,
            new ReportBuilder($jobs, new ImportExportErrorRepository($this->connection(), $jobs), $reports)
        );
        $job = $this->seedJob(['type' => 'import', 'adapter' => 'fake', 'status' => 'completed']);

        $data = $this->json($controller->show(Request::create('/'), $job['uuid']));

        self::assertSame($job['uuid'], $data['data']['report']['job_uuid']);
        self::assertSame('completed', $data['data']['report']['summary']['status']);
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
