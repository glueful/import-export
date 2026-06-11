<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import-export:cancel', description: 'Cancel an import/export job')]
final class ImportExportCancelCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Import/export job UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('job');

        try {
            $this->getService(ImportExportJobRepository::class)->cancel($uuid);
            $this->success(sprintf('Import/export job cancelled: %s', $uuid));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
