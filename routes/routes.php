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
     * @description Lists the importer and exporter adapters registered through the
     *   import_export.importer and import_export.exporter service tags, with their keys and labels.
     * @tag Import Export
     * @response 200 application/json "Adapters retrieved"
     * @response 403 "Permission denied (import_export.view)"
     */
    $router->get('/adapters', [ImportExportAdapterController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.adapters.index');

    /**
     * @route POST /import-export/imports
     * @summary Queue Import Job
     * @description
     *   Creates an import job for a registered importer adapter, plans deterministic batches,
     *   and queues one batch job per batch. Defaults to dry_run mode; pass mode=commit to write.
     * @tag Import Export
     * @requestBody
     *   adapter:string="Importer adapter key (see GET /import-export/adapters)" {required=adapter}
     *   path:string="Source file path on the storage disk" {required=path}
     *   disk:string="Source storage disk (default: uploads)"
     *   mime_type:string="Optional source MIME type hint"
     *   metadata:object="Optional source metadata passed to the adapter's supports()/plan()"
     *   mode:string="Import mode: dry_run|commit (default: dry_run)"
     *   batch_size:int="Requested records per batch (default: 500; the adapter's plan decides)"
     *   options:object="Adapter-specific options, available to the adapter during plan()"
     * @response 201 application/json "Import job queued"
     * @response 400 "Unknown adapter or source not supported by the adapter"
     * @response 403 "Permission denied (import_export.run_import)"
     * @response 422 "Validation failed (missing adapter or path)"
     */
    $router->post('/imports', [ImportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_import')
        ->name('import_export.imports.store');

    /**
     * @route POST /import-export/exports
     * @summary Queue Export Job
     * @description
     *   Creates an export job for a registered exporter adapter, plans deterministic batches,
     *   and queues one batch job per batch. Exports always run in commit mode.
     * @tag Import Export
     * @requestBody
     *   adapter:string="Exporter adapter key (see GET /import-export/adapters)" {required=adapter}
     *   format:string="Requested output format (default: ndjson; interpreted by the adapter's plan)"
     *   batch_size:int="Requested records per batch (default: 500; the adapter's plan decides)"
     *   filters:object="Adapter-specific record filters, available to the adapter during plan()"
     *   options:object="Adapter-specific options, available to the adapter during plan()"
     * @response 201 application/json "Export job queued"
     * @response 400 "Unknown adapter"
     * @response 403 "Permission denied (import_export.run_export)"
     * @response 422 "Validation failed (missing adapter)"
     */
    $router->post('/exports', [ExportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_export')
        ->name('import_export.exports.store');

    /**
     * @route GET /import-export/jobs
     * @summary List Import/Export Jobs
     * @description Lists import/export jobs, newest first, optionally filtered by type and status.
     * @tag Import Export
     * @queryParam type:string="Filter by job type: import|export"
     * @queryParam status:string="Filter by status: pending|planning|queued|running|completed|failed|cancelled"
     * @queryParam limit:int="Maximum jobs to return, 1-200 (default: 50)"
     * @response 200 application/json "Jobs retrieved"
     * @response 403 "Permission denied (import_export.view)"
     */
    $router->get('/jobs', [ImportJobController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.index');

    /**
     * @route GET /import-export/jobs/{uuid}
     * @summary Show Import/Export Job
     * @description Retrieves one job with its progress counters, links, and all of its batches.
     * @tag Import Export
     * @response 200 application/json "Job retrieved"
     * @response 403 "Permission denied (import_export.view)"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}', [ImportJobController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.show');

    /**
     * @route GET /import-export/jobs/{uuid}/errors
     * @summary List Import/Export Job Errors
     * @description
     *   Retrieves the stored row errors for one job. Errors are capped per severity;
     *   once the cap is reached, further errors only increment the job's error_overflow_count.
     * @tag Import Export
     * @response 200 application/json "Errors retrieved"
     * @response 403 "Permission denied (import_export.view)"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}/errors', [ImportJobController::class, 'errors'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.errors');

    /**
     * @route GET /import-export/jobs/{uuid}/report
     * @summary Show Import/Export Job Report
     * @description
     *   Returns the latest stored report for the job, or builds one on demand from the
     *   current job state (type, adapter, status, totals, failed and overflow counts).
     * @tag Import Export
     * @response 200 application/json "Report retrieved"
     * @response 403 "Permission denied (import_export.view)"
     * @response 404 "Job not found"
     */
    $router->get('/jobs/{uuid}/report', [ImportExportReportController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.report');

    /**
     * @route POST /import-export/jobs/{uuid}/cancel
     * @summary Cancel Import/Export Job
     * @description
     *   Cancels a pending, planning, queued, or running job and dispatches
     *   ImportExportJobCancelled. Batches that have not been claimed yet observe the
     *   cancellation and exit; an in-flight batch finishes its current run.
     * @tag Import Export
     * @response 200 application/json "Job cancelled"
     * @response 403 "Permission denied (import_export.cancel)"
     * @response 404 "Job not found"
     * @response 422 "Invalid status transition (job already completed, failed, or cancelled)"
     */
    $router->post('/jobs/{uuid}/cancel', [ImportJobController::class, 'cancel'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.cancel')
        ->name('import_export.jobs.cancel');

    /**
     * @route POST /import-export/jobs/{uuid}/retry
     * @summary Retry Import/Export Job
     * @description
     *   Re-queues the failed batches of a job whose adapter implements
     *   RetryableAdapterInterface and reports retryable() === true. Each failed batch is
     *   reset to pending and re-delivered in full, so retryable adapters must apply
     *   records idempotently (upsert by a stable source key).
     * @tag Import Export
     * @response 200 application/json "Retry queued"
     * @response 403 "Permission denied (import_export.retry)"
     * @response 404 "Job not found"
     * @response 422 "Adapter is not retryable"
     */
    $router->post('/jobs/{uuid}/retry', [ImportExportRetryController::class, 'retry'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.retry')
        ->name('import_export.jobs.retry');
});
