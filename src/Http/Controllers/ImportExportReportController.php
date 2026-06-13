<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportReportRepository;
use Glueful\Extensions\ImportExport\Services\ReportBuilder;
use Glueful\Http\Response;
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
