<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Events\EventService;
use Glueful\Extensions\ImportExport\Events\ImportExportJobCancelled;
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
            $jobs = $this->getService(ImportExportJobRepository::class);
            $job = $jobs->find($uuid);
            if ($job === null) {
                throw new \RuntimeException('Import/export job not found.');
            }

            $jobs->cancel($uuid);
            if ($this->getContainer()->has(EventService::class)) {
                $this->getService(EventService::class)->dispatch(new ImportExportJobCancelled(
                    $uuid,
                    (string) $job['type'],
                    (string) $job['adapter']
                ));
            }

            $this->success(sprintf('Import/export job cancelled: %s', $uuid));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
