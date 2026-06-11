<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportBatchFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCompleted;
use Glueful\Extensions\ImportExport\Events\ImportExportJobFailed;
use Glueful\Extensions\ImportExport\Events\ImportExportJobStarted;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;

use function config;

class BatchRunner
{
    public function __construct(
        private ApplicationContext $context,
        private ImporterRegistry $importers,
        private ExporterRegistry $exporters,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private ImportExportErrorRepository $errors,
        private ImportExportFileRepository $files,
        private ?EventService $events = null,
    ) {
    }

    public function runImportBatch(string $batchUuid): void
    {
        $batchRow = $this->batches->find($batchUuid);
        if ($batchRow === null) {
            throw new \RuntimeException(sprintf('Import batch "%s" was not found.', $batchUuid));
        }

        $job = $this->jobs->find((string) $batchRow['job_uuid']);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Import job "%s" was not found.', $batchRow['job_uuid']));
        }

        if ($job['status'] === 'cancelled') {
            return;
        }

        if (!$this->batches->claim($batchUuid, $this->staleLockCutoff())) {
            return;
        }

        $this->markRunning($job);

        try {
            $importer = $this->importers->get((string) $job['adapter']);
            $result = $importer->process(
                new ImportBatch(
                    uuid: (string) $batchRow['uuid'],
                    jobUuid: (string) $batchRow['job_uuid'],
                    sequence: (int) $batchRow['sequence'],
                    offset: (int) $batchRow['offset'],
                    limit: (int) $batchRow['limit'],
                ),
                new ImportContext(
                    app: $this->context,
                    jobUuid: (string) $job['uuid'],
                    mode: (string) $job['mode'],
                    actorUuid: $job['created_by'] ?? null,
                    options: $this->jsonArray($job['options'] ?? null),
                )
            );
        } catch (\Throwable $e) {
            $this->failClaimedBatch($job, $batchUuid, $e);
            return;
        }

        foreach ($result->errors as $error) {
            $this->errors->record((string) $job['uuid'], $batchUuid, $error, $this->errorCapPerSeverity());
        }

        $this->batches->complete($batchUuid, $result->processedRecords, $result->failedRecords);
        $this->dispatchBatchFinished($job, $batchUuid, $result->failedRecords, 'Batch completed with failed records.');
        $this->rollUpJob((string) $job['uuid']);
    }

    public function runExportBatch(string $batchUuid): void
    {
        $batchRow = $this->batches->find($batchUuid);
        if ($batchRow === null) {
            throw new \RuntimeException(sprintf('Export batch "%s" was not found.', $batchUuid));
        }

        $job = $this->jobs->find((string) $batchRow['job_uuid']);
        if ($job === null) {
            throw new \RuntimeException(sprintf('Export job "%s" was not found.', $batchRow['job_uuid']));
        }

        if ($job['status'] === 'cancelled') {
            return;
        }

        if (!$this->batches->claim($batchUuid, $this->staleLockCutoff())) {
            return;
        }

        $this->markRunning($job);

        try {
            $exporter = $this->exporters->get((string) $job['adapter']);
            $result = $exporter->process(
                new ExportBatch(
                    uuid: (string) $batchRow['uuid'],
                    jobUuid: (string) $batchRow['job_uuid'],
                    sequence: (int) $batchRow['sequence'],
                    offset: (int) $batchRow['offset'],
                    limit: (int) $batchRow['limit'],
                ),
                new ExportContext(
                    app: $this->context,
                    jobUuid: (string) $job['uuid'],
                    format: (string) ($job['format'] ?? 'ndjson'),
                    actorUuid: $job['created_by'] ?? null,
                    filters: $this->jsonArray($job['filters'] ?? null),
                    options: $this->jsonArray($job['options'] ?? null),
                )
            );
        } catch (\Throwable $e) {
            $this->failClaimedBatch($job, $batchUuid, $e);
            return;
        }

        foreach ($result->errors as $error) {
            $this->errors->record((string) $job['uuid'], $batchUuid, $error, $this->errorCapPerSeverity());
        }

        if ($result->resultPath !== null) {
            $this->files->create([
                'job_uuid' => $job['uuid'],
                'role' => 'result',
                'disk' => $job['result_disk'] ?? 'local',
                'path' => $result->resultPath,
            ]);
        }

        $this->batches->complete($batchUuid, $result->processedRecords, $result->failedRecords);
        $this->dispatchBatchFinished($job, $batchUuid, $result->failedRecords, 'Batch completed with failed records.');
        $this->rollUpJob((string) $job['uuid']);
    }

    /** @param array<string,mixed> $job */
    private function markRunning(array $job): void
    {
        if (in_array($job['status'], ['planning', 'queued'], true)) {
            $this->jobs->transition((string) $job['uuid'], 'running');
            $this->events?->dispatch(new ImportExportJobStarted(
                (string) $job['uuid'],
                (string) $job['type'],
                (string) $job['adapter'],
            ));
        }
    }

    private function staleLockCutoff(): string
    {
        $minutes = max(1, (int) config($this->context, 'import_export.stale_lock_minutes', 15));

        return sprintf('-%d minutes', $minutes);
    }

    private function errorCapPerSeverity(): int
    {
        return max(1, (int) config($this->context, 'import_export.error_cap_per_severity', 1000));
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $job */
    private function failClaimedBatch(array $job, string $batchUuid, \Throwable $e): void
    {
        $this->errors->record((string) $job['uuid'], $batchUuid, [
            'severity' => 'error',
            'code' => 'adapter_exception',
            'message' => $e->getMessage(),
            'context' => [
                'exception' => $e::class,
            ],
        ], $this->errorCapPerSeverity());
        $this->batches->complete($batchUuid, 0, 1);
        $this->events?->dispatch(new ImportExportBatchFailed(
            (string) $job['uuid'],
            $batchUuid,
            (string) $job['type'],
            (string) $job['adapter'],
            $e->getMessage(),
        ));
        $this->rollUpJob((string) $job['uuid']);
    }

    /** @param array<string,mixed> $job */
    private function dispatchBatchFinished(array $job, string $batchUuid, int $failedRecords, string $reason): void
    {
        if ($failedRecords > 0) {
            $this->events?->dispatch(new ImportExportBatchFailed(
                (string) $job['uuid'],
                $batchUuid,
                (string) $job['type'],
                (string) $job['adapter'],
                $reason,
            ));
            return;
        }

        $this->events?->dispatch(new ImportExportBatchCompleted(
            (string) $job['uuid'],
            $batchUuid,
            (string) $job['type'],
            (string) $job['adapter'],
        ));
    }

    private function rollUpJob(string $jobUuid): void
    {
        $batches = $this->batches->forJob($jobUuid);
        $processed = 0;
        $failed = 0;
        $open = false;

        foreach ($batches as $batch) {
            $processed += (int) $batch['processed_records'];
            $failed += (int) $batch['failed_records'];
            if (in_array($batch['status'], ['pending', 'running'], true)) {
                $open = true;
            }
        }

        $this->jobs->updateProgress($jobUuid, $processed, $failed);
        if ($open) {
            return;
        }

        $job = $this->jobs->find($jobUuid);
        if ($job === null || in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $toStatus = $failed > 0 ? 'failed' : 'completed';
        $this->jobs->transition($jobUuid, $toStatus);
        $updatedJob = $this->jobs->find($jobUuid);
        if ($updatedJob === null) {
            return;
        }

        if ($toStatus === 'failed') {
            $this->events?->dispatch(new ImportExportJobFailed(
                $jobUuid,
                (string) $updatedJob['type'],
                (string) $updatedJob['adapter'],
                'One or more batches failed.',
            ));
            return;
        }

        $this->events?->dispatch(new ImportExportJobCompleted(
            $jobUuid,
            (string) $updatedJob['type'],
            (string) $updatedJob['adapter'],
        ));
    }
}
