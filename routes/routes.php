<?php

declare(strict_types=1);

use Glueful\Extensions\ImportExport\Http\Controllers\ExportJobController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportAdapterController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportJobController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by the extension service provider. */

$router->group(['prefix' => '/import-export', 'middleware' => ['auth']], function (Router $router): void {
    /**
     * @route GET /import-export/adapters
     * @summary List Import/Export Adapters
     * @description Lists registered importer and exporter adapters.
     * @tag Import Export
     * @response 200 application/json "Adapters retrieved"
     */
    $router->get('/adapters', [ImportExportAdapterController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.adapters.index');

    /**
     * @route POST /import-export/imports
     * @summary Queue Import Job
     * @description Creates an import job and queues its batches.
     * @tag Import Export
     * @response 201 application/json "Import job queued"
     */
    $router->post('/imports', [ImportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_import')
        ->name('import_export.imports.store');

    /**
     * @route POST /import-export/exports
     * @summary Queue Export Job
     * @description Creates an export job and queues its batches.
     * @tag Import Export
     * @response 201 application/json "Export job queued"
     */
    $router->post('/exports', [ExportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_export')
        ->name('import_export.exports.store');

    /**
     * @route GET /import-export/jobs
     * @summary List Import/Export Jobs
     * @description Lists jobs, optionally filtered by type and status.
     * @tag Import Export
     * @response 200 application/json "Jobs retrieved"
     */
    $router->get('/jobs', [ImportJobController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.index');

    /**
     * @route GET /import-export/jobs/{uuid}
     * @summary Show Import/Export Job
     * @description Retrieves one job with its batches.
     * @tag Import Export
     * @response 200 application/json "Job retrieved"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}', [ImportJobController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.show');

    /**
     * @route GET /import-export/jobs/{uuid}/errors
     * @summary List Import/Export Job Errors
     * @description Retrieves stored validation or processing errors for one job.
     * @tag Import Export
     * @response 200 application/json "Errors retrieved"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}/errors', [ImportJobController::class, 'errors'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.errors');

    /**
     * @route GET /import-export/jobs/{uuid}/report
     * @summary Show Import/Export Job Report
     * @description Retrieves the latest report or builds one from the stored job state.
     * @tag Import Export
     * @response 200 application/json "Report retrieved"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}/report', [ImportExportReportController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.report');

    /**
     * @route POST /import-export/jobs/{uuid}/cancel
     * @summary Cancel Import/Export Job
     * @description Cancels a queued or running job.
     * @tag Import Export
     * @response 200 application/json "Job cancelled"
     * @response 404 "Job not found"
     * @response 422 "Invalid transition"
     */
    $router->post('/jobs/{uuid}/cancel', [ImportJobController::class, 'cancel'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.cancel')
        ->name('import_export.jobs.cancel');

    /**
     * @route POST /import-export/jobs/{uuid}/retry
     * @summary Retry Import/Export Job
     * @description Explicitly retries failed batches for a retryable adapter.
     * @tag Import Export
     * @response 200 application/json "Retry queued"
     */
    $router->post('/jobs/{uuid}/retry', [ImportExportRetryController::class, 'retry'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.retry')
        ->name('import_export.jobs.retry');
});
