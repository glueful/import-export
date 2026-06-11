<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ImportJobController
{
    public function __construct(
        private ImportExportService $service,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private ImportExportErrorRepository $errors,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::success([
            'jobs' => $this->jobs->list(
                $this->optionalQuery($request, 'type'),
                $this->optionalQuery($request, 'status'),
                max(1, min(200, (int) $request->query->get('limit', 50)))
            ),
        ], 'Import/export jobs retrieved.');
    }

    public function store(Request $request): Response
    {
        try {
            $data = $this->body($request);
            $job = $this->service->createImport(
                $this->requiredString($data, 'adapter'),
                new ImportSource(
                    disk: (string) ($data['disk'] ?? 'uploads'),
                    path: $this->requiredString($data, 'path'),
                    mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
                    metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
                ),
                new ImportOptions(
                    mode: (string) ($data['mode'] ?? 'dry_run'),
                    batchSize: (int) ($data['batch_size'] ?? 500),
                    actorUuid: $this->actorUuid($request),
                    options: is_array($data['options'] ?? null) ? $data['options'] : []
                )
            );

            return Response::created(['job' => $this->withLinks($job)], 'Import job queued.');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['import' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null) {
            return Response::notFound('Import/export job not found.');
        }

        return Response::success([
            'job' => $this->withLinks($job),
            'batches' => $this->batches->forJob($uuid),
        ], 'Import/export job retrieved.');
    }

    public function errors(Request $request, string $uuid): Response
    {
        if ($this->jobs->find($uuid) === null) {
            return Response::notFound('Import/export job not found.');
        }

        return Response::success([
            'errors' => $this->errors->forJob($uuid),
        ], 'Import/export job errors retrieved.');
    }

    public function cancel(Request $request, string $uuid): Response
    {
        try {
            $this->jobs->cancel($uuid);

            return Response::success(['job' => $this->jobs->find($uuid)], 'Import/export job cancelled.');
        } catch (\RuntimeException) {
            return Response::notFound('Import/export job not found.');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['job' => $e->getMessage()]);
        }
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

    private function optionalQuery(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function actorUuid(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');

        return $user instanceof UserIdentity ? $user->id() : null;
    }

    /** @param array<string,mixed> $job */
    private function withLinks(array $job): array
    {
        $uuid = (string) ($job['uuid'] ?? '');
        $job['links'] = [
            'self' => "/import-export/jobs/{$uuid}",
            'errors' => "/import-export/jobs/{$uuid}/errors",
            'report' => "/import-export/jobs/{$uuid}/report",
        ];

        return $job;
    }
}
