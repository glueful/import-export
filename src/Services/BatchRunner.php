<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportErrorRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;

class BatchRunner
{
    public function __construct(
        private ApplicationContext $context,
        private ImporterRegistry $importers,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private ImportExportErrorRepository $errors,
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
    }

    public function runExportBatch(string $batchUuid): void
    {
        $batchRow = $this->batches->find($batchUuid);
        if ($batchRow === null) {
            throw new \RuntimeException(sprintf('Export batch "%s" was not found.', $batchUuid));
        }

        if (!$this->batches->claim($batchUuid, '-15 minutes')) {
            return;
        }

        $this->batches->complete($batchUuid, 0, 0);
    }
}
