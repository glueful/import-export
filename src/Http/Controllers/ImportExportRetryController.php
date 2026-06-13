<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ImportExportRetryController
{
    public function __construct(
        private RetryService $retry,
        private ImportExportJobRepository $jobs,
        private JobAccess $access,
    ) {
    }

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
