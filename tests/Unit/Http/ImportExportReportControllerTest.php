<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Http\JobAccess;
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
            new ReportBuilder($jobs, new ImportExportErrorRepository($this->connection(), $jobs), $reports),
            new JobAccess($this->appContext())
        );
        $job = $this->seedJob(['type' => 'import', 'adapter' => 'fake', 'status' => 'completed', 'created_by' => 'user-1']);
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        $data = $this->json($controller->show($request, $job['uuid']));

        self::assertSame($job['uuid'], $data['data']['report']['job_uuid']);
        self::assertSame('completed', $data['data']['report']['summary']['status']);
    }

    public function testReportEndpointHidesJobsOwnedByAnotherUser(): void
    {
        $jobs = new ImportExportJobRepository($this->connection());
        $reports = new ImportExportReportRepository($this->connection());
        $controller = new ImportExportReportController(
            $jobs,
            $reports,
            new ReportBuilder($jobs, new ImportExportErrorRepository($this->connection(), $jobs), $reports),
            new JobAccess($this->appContext())
        );
        $job = $this->seedJob(['type' => 'import', 'adapter' => 'fake', 'status' => 'completed', 'created_by' => 'other-user']);
        $request = Request::create('/');
        $request->attributes->set('auth.user', new UserIdentity('user-1'));

        self::assertSame(404, $controller->show($request, $job['uuid'])->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
