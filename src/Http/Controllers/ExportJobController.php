<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Http\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ExportJobController
{
    public function __construct(private ImportExportService $service)
    {
    }

    public function store(Request $request): Response
    {
        try {
            $data = $this->body($request);
            $job = $this->service->createExport(
                $this->requiredString($data, 'adapter'),
                new ExportOptions(
                    format: (string) ($data['format'] ?? 'ndjson'),
                    batchSize: (int) ($data['batch_size'] ?? 500),
                    actorUuid: $this->actorUuid($request),
                    filters: is_array($data['filters'] ?? null) ? $data['filters'] : [],
                    options: is_array($data['options'] ?? null) ? $data['options'] : []
                )
            );

            return Response::created(['job' => $this->withLinks($job)], 'Export job queued.');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['export' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
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
