<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCreated;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Queue\QueueManager;

final class ImportExportService
{
    public function __construct(
        private ImporterRegistry $importers,
        private ExporterRegistry $exporters,
        private ImportExportJobRepository $jobs,
        private ImportExportBatchRepository $batches,
        private ImportExportFileRepository $files,
        private QueueManager $queue,
        private string $queueName = 'import-export',
        private ?EventService $events = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function createImport(string $adapterKey, ImportSource $source, ImportOptions $options): array
    {
        $importer = $this->importers->get($adapterKey);
        if (!$importer->supports($source)) {
            throw new \RuntimeException(sprintf('Importer "%s" does not support the provided source.', $adapterKey));
        }

        $plan = $importer->plan($source, $options);
        $job = $this->jobs->create([
            'type' => 'import',
            'adapter' => $adapterKey,
            'status' => 'queued',
            'mode' => $options->mode,
            'source_disk' => $source->disk,
            'source_path' => $source->path,
            'total_records' => $plan->totalRecords,
            'created_by' => $options->actorUuid,
        ]);

        $this->files->create([
            'job_uuid' => $job['uuid'],
            'role' => 'source',
            'disk' => $source->disk,
            'path' => $source->path,
            'mime_type' => $source->mimeType,
        ]);

        foreach ($plan->batches as $batch) {
            $row = $this->batches->create([
                'uuid' => $batch->uuid,
                'job_uuid' => $job['uuid'],
                'sequence' => $batch->sequence,
                'status' => 'pending',
                'offset' => $batch->offset,
                'limit' => $batch->limit,
            ]);

            $this->queue->push(ProcessImportBatchJob::class, [
                'job_uuid' => $job['uuid'],
                'batch_uuid' => $row['uuid'],
                'adapter' => $adapterKey,
                'retryable' => $plan->retryable,
            ], $this->queueName);
        }

        $this->events?->dispatch(new ImportExportJobCreated($job['uuid'], 'import', $adapterKey));

        return $this->jobs->find($job['uuid']) ?? $job;
    }

    /** @return array<string,mixed> */
    public function createExport(string $adapterKey, ExportOptions $options): array
    {
        $exporter = $this->exporters->get($adapterKey);
        $plan = $exporter->plan($options);
        $job = $this->jobs->create([
            'type' => 'export',
            'adapter' => $adapterKey,
            'status' => 'queued',
            'mode' => 'commit',
            'total_records' => $plan->totalRecords,
            'created_by' => $options->actorUuid,
        ]);

        foreach ($plan->batches as $batch) {
            $row = $this->batches->create([
                'uuid' => $batch->uuid,
                'job_uuid' => $job['uuid'],
                'sequence' => $batch->sequence,
                'status' => 'pending',
                'offset' => $batch->offset,
                'limit' => $batch->limit,
            ]);

            $this->queue->push(ProcessExportBatchJob::class, [
                'job_uuid' => $job['uuid'],
                'batch_uuid' => $row['uuid'],
                'adapter' => $adapterKey,
                'retryable' => $plan->retryable,
            ], $this->queueName);
        }

        $this->events?->dispatch(new ImportExportJobCreated($job['uuid'], 'export', $adapterKey));

        return $this->jobs->find($job['uuid']) ?? $job;
    }
}
