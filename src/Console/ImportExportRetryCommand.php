<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Services\RetryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import-export:retry', description: 'Retry failed batches for a retryable import/export job')]
final class ImportExportRetryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Import/export job UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobUuid = (string) $input->getArgument('job');

        try {
            $this->getService(RetryService::class)->retry($jobUuid);
            $this->io->success(sprintf('Retry queued for import/export job %s.', $jobUuid));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
