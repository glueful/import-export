<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class FailedRecordExportController
{
    public function __construct(
        private ImportExportJobRepository $jobs,
        private FailedRecordExporter $exporter,
    ) {
    }

    public function export(Request $request, string $uuid): Response
    {
        if ($this->jobs->find($uuid) === null) {
            return Response::notFound('Import/export job not found.');
        }

        try {
            $data = $this->body($request);
            $path = $this->requiredString($data, 'path');
            $format = isset($data['format']) && is_scalar($data['format'])
                ? (string) $data['format']
                : 'ndjson';

            $this->exporter->export($uuid, $path, $format);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['failed_records' => $e->getMessage()]);
        }

        return Response::success([
            'uuid' => $uuid,
            'path' => $path,
            'format' => $format,
        ], 'Failed records exported.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $content = $request->getContent();
        $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];

        return array_merge(
            $request->query->all(),
            $request->request->all(),
            is_array($data) ? $data : []
        );
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || (string) $value === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is required.', $key));
        }

        return (string) $value;
    }
}
