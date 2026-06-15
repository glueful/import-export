<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCancelled;
use Glueful\Extensions\ImportExport\Http\JobAccess;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Request;

use function config;

final class ImportJobController
{
    public function __construct(
        private ApplicationContext $context,
        private ImportExportService $service,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private ImportExportErrorRepository $errors,
        private JobAccess $access,
        private ?EventService $events = null,
    ) {
    }

    /**
     * List the caller's import/export jobs.
     */
    #[ApiOperation(
        summary: 'List Import/Export Jobs',
        description: "Lists the caller's import/export jobs, newest first, optionally filtered by "
            . 'type and status. Users with `import_export.manage_all` can see all jobs. '
            . 'Requires the `import_export.view` permission.',
        tags: ['Import Export'],
    )]
    #[QueryParam('type', description: 'Filter by job type', enum: ['import', 'export'])]
    #[QueryParam(
        'status',
        description: 'Filter by status',
        enum: ['pending', 'planning', 'queued', 'running', 'completed', 'failed', 'cancelled'],
    )]
    #[QueryParam('limit', 'integer', description: 'Maximum jobs to return, 1-200 (default: 50)')]
    #[ApiResponse(200, description: 'Jobs retrieved')]
    #[ApiResponse(403, description: 'Permission denied (import_export.view)')]
    public function index(Request $request): Response
    {
        $actorUuid = $this->access->canManageAll($request)
            ? null
            : $this->access->actorUuid($request);

        return Response::success([
            'jobs' => $this->jobs->list(
                $this->optionalQuery($request, 'type'),
                $this->optionalQuery($request, 'status'),
                max(1, min(200, (int) $request->query->get('limit', 50))),
                $actorUuid
            ),
        ], 'Import/export jobs retrieved.');
    }

    /**
     * Queue an import job for a registered importer adapter.
     */
    #[ApiOperation(
        summary: 'Queue Import Job',
        description: 'Creates an import job for a registered importer adapter, plans deterministic '
            . 'batches, and queues one batch job per batch. Defaults to `dry_run` mode; pass '
            . '`mode=commit` to write. Body: `adapter` (required; importer adapter key, see '
            . 'GET /import-export/adapters), `path` (required; relative source file path under the '
            . 'configured source disk root), `disk` (source storage disk, default: uploads), '
            . '`mime_type` (optional source MIME type hint), `metadata` (optional source metadata '
            . "passed to the adapter's supports()/plan(); size_bytes is ignored), `mode` "
            . '(import mode: dry_run|commit, default: dry_run), `batch_size` (requested records per '
            . "batch, default: 500; the adapter's plan decides), `options` (adapter-specific options, "
            . 'available to the adapter during plan()). Requires the `import_export.run_import` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(201, description: 'Import job queued')]
    #[ApiResponse(400, description: 'Unknown adapter or source not supported by the adapter')]
    #[ApiResponse(403, description: 'Permission denied (import_export.run_import)')]
    #[ApiResponse(422, description: 'Validation failed (missing adapter or path)')]
    public function store(Request $request): Response
    {
        try {
            $data = $this->body($request);
            $job = $this->service->createImport(
                $this->requiredString($data, 'adapter'),
                new ImportSource(
                    disk: (string) ($data['disk']
                        ?? config($this->context, 'import_export.source_disk', 'uploads')),
                    path: $this->requiredString($data, 'path'),
                    mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
                    metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
                ),
                new ImportOptions(
                    mode: (string) ($data['mode'] ?? 'dry_run'),
                    batchSize: (int) ($data['batch_size']
                        ?? config($this->context, 'import_export.batch_size', 500)),
                    actorUuid: $this->access->actorUuid($request),
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

    /**
     * Retrieve one caller-owned job with its batches.
     */
    #[ApiOperation(
        summary: 'Show Import/Export Job',
        description: 'Retrieves one caller-owned job with its progress counters, links, and all of '
            . 'its batches. Users with `import_export.manage_all` can retrieve any job. '
            . 'Requires the `import_export.view` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Job retrieved')]
    #[ApiResponse(403, description: 'Permission denied (import_export.view)')]
    #[ApiResponse(404, description: 'Job not found')]
    public function show(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null || !$this->access->canAccess($request, $job)) {
            return Response::notFound('Import/export job not found.');
        }

        return Response::success([
            'job' => $this->withLinks($job),
            'batches' => $this->batches->forJob($uuid),
        ], 'Import/export job retrieved.');
    }

    /**
     * Retrieve the stored row errors for one caller-owned job.
     */
    #[ApiOperation(
        summary: 'List Import/Export Job Errors',
        description: 'Retrieves the stored row errors for one caller-owned job. Errors are capped per '
            . 'severity; once the cap is reached, further errors only increment the job\'s '
            . '`error_overflow_count`. Users with `import_export.manage_all` can retrieve errors for '
            . 'any job. Requires the `import_export.view` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Errors retrieved')]
    #[ApiResponse(403, description: 'Permission denied (import_export.view)')]
    #[ApiResponse(404, description: 'Job not found')]
    public function errors(Request $request, string $uuid): Response
    {
        $job = $this->jobs->find($uuid);
        if ($job === null || !$this->access->canAccess($request, $job)) {
            return Response::notFound('Import/export job not found.');
        }

        return Response::success([
            'errors' => $this->errors->forJob($uuid),
        ], 'Import/export job errors retrieved.');
    }

    /**
     * Cancel a caller-owned pending, planning, queued, or running job.
     */
    #[ApiOperation(
        summary: 'Cancel Import/Export Job',
        description: 'Cancels a caller-owned pending, planning, queued, or running job and dispatches '
            . 'ImportExportJobCancelled. Batches that have not been claimed yet observe the '
            . 'cancellation and exit; an in-flight batch finishes its current run. Users with '
            . '`import_export.manage_all` can cancel any job. Requires the `import_export.cancel` permission.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'Job cancelled')]
    #[ApiResponse(403, description: 'Permission denied (import_export.cancel)')]
    #[ApiResponse(404, description: 'Job not found')]
    #[ApiResponse(422, description: 'Invalid status transition (job already completed, failed, or cancelled)')]
    public function cancel(Request $request, string $uuid): Response
    {
        try {
            $job = $this->jobs->find($uuid);
            if ($job === null || !$this->access->canAccess($request, $job)) {
                return Response::notFound('Import/export job not found.');
            }

            $this->jobs->cancel($uuid);
            $this->events?->dispatch(new ImportExportJobCancelled(
                $uuid,
                (string) $job['type'],
                (string) $job['adapter']
            ));

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

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
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
