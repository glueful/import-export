<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Glueful\Extensions\ImportExport\Support\PathGuard;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

use function config;

final class FailedRecordExportController
{
    public function __construct(
        private ApplicationContext $context,
        private ImportExportJobRepository $jobs,
        private FailedRecordExporter $exporter,
        private JobAccess $access,
    ) {
    }

    /**
     * Write a caller-owned job's stored failed records to a managed private file.
     */
    #[ApiOperation(
        summary: 'Export Failed Records',
        description: 'Writes the stored failed-record errors for a caller-owned job to a managed '
            . 'private ndjson or csv file. Users with `import_export.manage_all` can export failures '
            . 'for any job. Body: `format` (output format: ndjson|csv, default: ndjson). '
            . 'Requires the `import_export.export_failed_records` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Failed records exported')]
    #[ApiResponse(403, description: 'Permission denied (import_export.export_failed_records)')]
    #[ApiResponse(404, description: 'Job not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function export(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null || !$this->access->canAccess($request, $job)) {
            return Response::notFound('Import/export job not found.');
        }

        try {
            $data = $this->body($request);
            $format = isset($data['format']) && is_scalar($data['format'])
                ? (string) $data['format']
                : 'ndjson';
            $relativePath = $this->managedFailedRecordsPath($uuid, $format);
            $path = PathGuard::resolveUnderRoot($this->managedRoot(), $relativePath);

            $this->exporter->export($uuid, $path, $format);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['failed_records' => $e->getMessage()]);
        }

        return Response::success([
            'uuid' => $uuid,
            'disk' => 'local',
            'path' => $relativePath,
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

    private function managedFailedRecordsPath(string $uuid, string $format): string
    {
        $extension = match ($format) {
            'csv' => 'csv',
            'ndjson' => 'ndjson',
            default => throw new \InvalidArgumentException(sprintf('Unsupported failed-record format "%s".', $format)),
        };

        return sprintf('failed-records/%s.%s', $uuid, $extension);
    }

    private function managedRoot(): string
    {
        $configured = config($this->context, 'import_export.private_path', null);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim($this->context->getBasePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'import-export';
    }
}
