<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Extensions\ImportExport\Files\CsvWriter;
use Glueful\Extensions\ImportExport\Files\NdjsonWriter;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;

final class FailedRecordExporter
{
    public function __construct(private ImportExportErrorRepository $errors)
    {
    }

    public function export(string $jobUuid, string $path, string $format = 'ndjson'): void
    {
        $rows = array_map(static function (array $error): array {
            return [
                'record_number' => isset($error['record_number']) ? (int) $error['record_number'] : null,
                'severity' => $error['severity'],
                'code' => $error['code'],
                'message' => $error['message'],
                'context' => $error['context'],
            ];
        }, $this->errors->forJob($jobUuid));

        match ($format) {
            'csv' => (new CsvWriter())->write($path, $rows),
            'ndjson' => (new NdjsonWriter())->write($path, $rows),
            default => throw new \InvalidArgumentException(sprintf('Unsupported failed-record format "%s".', $format)),
        };
    }
}
