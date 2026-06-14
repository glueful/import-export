<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportRetryController
{
    public function __construct(
        private RetryService $retry,
        private ImportExportJobRepository $jobs,
        private JobAccess $access,
    ) {
    }

    /**
     * Re-queue the failed batches of a caller-owned retryable job.
     */
    #[ApiOperation(
        summary: 'Retry Import/Export Job',
        description: 'Re-queues the failed batches of a caller-owned job whose adapter implements '
            . 'RetryableAdapterInterface and reports retryable() === true. Each failed batch is reset '
            . 'to pending and re-delivered in full, so retryable adapters must apply records '
            . 'idempotently (upsert by a stable source key). Users with `import_export.manage_all` '
            . 'can retry any job. Requires the `import_export.retry` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Retry queued')]
    #[ApiResponse(403, description: 'Permission denied (import_export.retry)')]
    #[ApiResponse(404, description: 'Job not found')]
    #[ApiResponse(422, description: 'Adapter is not retryable')]
    public function retry(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null || !$this->access->canAccess($request, $job)) {
            return Response::notFound('Import/export job not found.');
        }

        try {
            $this->retry->retry($uuid);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'was not found')) {
                return Response::notFound('Import/export job not found.');
            }

            return Response::validation(['retry' => $e->getMessage()]);
        }

        return Response::success(['uuid' => $uuid], 'Import/export job retry queued.');
    }
}
