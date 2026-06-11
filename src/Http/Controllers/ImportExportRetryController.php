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
        $this->retry->retry($uuid);

        return Response::success(['uuid' => $uuid], 'Import/export job retry queued.');
    }
}
