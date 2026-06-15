<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportReportController
{
    public function __construct(
        private ImportExportJobRepository $jobs,
        private ImportExportReportRepository $reports,
        private ReportBuilder $builder,
        private JobAccess $access,
    ) {
    }

    /**
     * Return the latest stored report for a caller-owned job, or build one on demand.
     */
    #[ApiOperation(
        summary: 'Show Import/Export Job Report',
        description: 'Returns the latest stored report for a caller-owned job, or builds one on demand '
            . 'from the current job state (type, adapter, status, totals, failed and overflow counts). '
            . 'Users with `import_export.manage_all` can retrieve reports for any job. '
            . 'Requires the `import_export.view` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Report retrieved')]
    #[ApiResponse(403, description: 'Permission denied (import_export.view)')]
    #[ApiResponse(404, description: 'Job not found')]
    public function show(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null || !$this->access->canAccess($request, $job)) {
            return Response::notFound('Import/export job not found.');
        }

        $report = $this->reports->latestForJob($uuid) ?? $this->builder->build($uuid);
        if (isset($report['summary']) && is_string($report['summary'])) {
            $decoded = json_decode($report['summary'], true);
            if (is_array($decoded)) {
                $report['summary'] = $decoded;
            }
        }

        return Response::success(['report' => $report], 'Import/export report retrieved.');
    }
}
