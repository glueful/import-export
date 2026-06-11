<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Services\RetryService;
use Glueful\Http\Response;

final class ImportExportRetryController
{
    public function __construct(private RetryService $retry)
    {
    }

    public function retry(string $uuid): Response
    {
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
