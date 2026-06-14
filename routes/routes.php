<?php

declare(strict_types=1);

use Glueful\Extensions\ImportExport\Http\Controllers\ExportJobController;
use Glueful\Extensions\ImportExport\Http\Controllers\FailedRecordExportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportAdapterController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportReportController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportExportRetryController;
use Glueful\Extensions\ImportExport\Http\Controllers\ImportJobController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by the extension service provider. */

$router->group(['prefix' => '/import-export', 'middleware' => ['auth']], function (Router $router): void {
    // Adapters
    $router->get('/adapters', [ImportExportAdapterController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.adapters.index');

    // Import / export job creation
    $router->post('/imports', [ImportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_import')
        ->name('import_export.imports.store');

    $router->post('/exports', [ExportJobController::class, 'store'])
        ->middleware('import_export_permission:import_export.run_export')
        ->name('import_export.exports.store');

    // Job listing and inspection
    $router->get('/jobs', [ImportJobController::class, 'index'])
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.index');

    $router->get('/jobs/{uuid}', [ImportJobController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.show');

    $router->get('/jobs/{uuid}/errors', [ImportJobController::class, 'errors'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.errors');

    $router->get('/jobs/{uuid}/report', [ImportExportReportController::class, 'show'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.view')
        ->name('import_export.jobs.report');

    // Job lifecycle actions
    $router->post('/jobs/{uuid}/cancel', [ImportJobController::class, 'cancel'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.cancel')
        ->name('import_export.jobs.cancel');

    $router->post('/jobs/{uuid}/retry', [ImportExportRetryController::class, 'retry'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.retry')
        ->name('import_export.jobs.retry');

    $router->post('/jobs/{uuid}/failed-records/export', [FailedRecordExportController::class, 'export'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('import_export_permission:import_export.export_failed_records')
        ->name('import_export.jobs.failed_records.export');
});
