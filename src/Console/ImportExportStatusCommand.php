<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Repositories\ImportExportBatchRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import-export:status', description: 'Show import/export job status')]
final class ImportExportStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Import/export job UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('job');
        $job = $this->getService(ImportExportJobRepository::class)->find($uuid);
        if ($job === null) {
            $this->error(sprintf('Import/export job "%s" was not found.', $uuid));
            return self::FAILURE;
        }

        $this->table(['UUID', 'Type', 'Adapter', 'Status', 'Total', 'Processed', 'Failed'], [[
            (string) $job['uuid'],
            (string) $job['type'],
            (string) $job['adapter'],
            (string) $job['status'],
            (string) $job['total_records'],
            (string) $job['processed_records'],
            (string) $job['failed_records'],
        ]]);

        $batches = $this->getService(ImportExportBatchRepository::class)->forJob($uuid);
        $this->table(['Batch', 'Status', 'Offset', 'Limit', 'Attempts'], array_map(
            static fn(array $batch): array => [
                (string) $batch['uuid'],
                (string) $batch['status'],
                (string) $batch['offset'],
                (string) $batch['limit'],
                (string) $batch['attempts'],
            ],
            $batches
        ));

        return self::SUCCESS;
    }
}
