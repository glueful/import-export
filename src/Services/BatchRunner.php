<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Bootstrap\ApplicationContext;
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

        if (!$this->batches->claim($batchUuid, '-15 minutes')) {
            return;
        }

        $this->markRunning($job);

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
            )
        );

        foreach ($result->errors as $error) {
            $this->errors->record((string) $job['uuid'], $batchUuid, $error);
        }

        $this->batches->complete($batchUuid, $result->processedRecords, $result->failedRecords);
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

        if (!$this->batches->claim($batchUuid, '-15 minutes')) {
            return;
        }

        $this->markRunning($job);

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
                format: 'ndjson',
                actorUuid: $job['created_by'] ?? null,
            )
        );

        foreach ($result->errors as $error) {
            $this->errors->record((string) $job['uuid'], $batchUuid, $error);
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
        $this->rollUpJob((string) $job['uuid']);
    }

    /** @param array<string,mixed> $job */
    private function markRunning(array $job): void
    {
        if (in_array($job['status'], ['planning', 'queued'], true)) {
            $this->jobs->transition((string) $job['uuid'], 'running');
        }
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

        $this->jobs->transition($jobUuid, $failed > 0 ? 'failed' : 'completed');
    }
}
