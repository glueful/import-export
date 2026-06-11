<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCreated;
use Glueful\Extensions\ImportExport\Jobs\ProcessExportBatchJob;
use Glueful\Extensions\ImportExport\Jobs\ProcessImportBatchJob;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Queue\QueueManager;

use function config;

final class ImportExportService
{
    public function __construct(
        private ApplicationContext $context,
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
        $sizeBytes = $this->guardMaxFileSize($source);

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
            'options' => $this->encodeJson($options->options),
            'total_records' => $plan->totalRecords,
            'created_by' => $options->actorUuid,
        ]);

        $this->files->create([
            'job_uuid' => $job['uuid'],
            'role' => 'source',
            'disk' => $source->disk,
            'path' => $source->path,
            'mime_type' => $source->mimeType,
            'size_bytes' => $sizeBytes ?? 0,
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
            'format' => $options->format,
            'result_disk' => (string) config($this->context, 'import_export.result_disk', 'uploads'),
            'filters' => $this->encodeJson($options->filters),
            'options' => $this->encodeJson($options->options),
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

    /**
     * Enforce the configured max source file size and return the size when known.
     *
     * Size resolution: an explicit `size_bytes` source metadata entry wins; otherwise
     * the path is probed as a local file. Unknown sizes are not rejected.
     */
    private function guardMaxFileSize(ImportSource $source): ?int
    {
        $sizeBytes = $this->sourceSizeBytes($source);
        $maxFileSize = (int) config($this->context, 'import_export.max_file_size', 52428800);

        if ($maxFileSize > 0 && $sizeBytes !== null && $sizeBytes > $maxFileSize) {
            throw new \InvalidArgumentException(sprintf(
                'Source file is %d bytes which exceeds the configured maximum of %d bytes.',
                $sizeBytes,
                $maxFileSize
            ));
        }

        return $sizeBytes;
    }

    private function sourceSizeBytes(ImportSource $source): ?int
    {
        $metadataSize = $source->metadata['size_bytes'] ?? null;
        if (is_numeric($metadataSize)) {
            return (int) $metadataSize;
        }

        if (is_file($source->path)) {
            $size = filesize($source->path);

            return $size === false ? null : $size;
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function encodeJson(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
