<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Services\FailedRecordExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import-export:failed-records', description: 'Export failed record errors for a job')]
final class ImportExportFailedRecordsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Import/export job UUID');
        $this->addArgument('path', InputArgument::REQUIRED, 'Output path');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: ndjson|csv', 'ndjson');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobUuid = (string) $input->getArgument('job');
        $path = (string) $input->getArgument('path');
        $format = (string) $input->getOption('format');

        try {
            if ($this->getService(ImportExportJobRepository::class)->find($jobUuid) === null) {
                throw new \RuntimeException(sprintf('Import/export job "%s" was not found.', $jobUuid));
            }

            $this->getService(FailedRecordExporter::class)->export($jobUuid, $path, $format);
            $this->success(sprintf('Failed records exported: %s', $path));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
