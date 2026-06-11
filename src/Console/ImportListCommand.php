<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:list', description: 'List import jobs')]
final class ImportListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs to show', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = $this->getService(ImportExportJobRepository::class)->list(
            'import',
            is_scalar($input->getOption('status')) ? (string) $input->getOption('status') : null,
            (int) $input->getOption('limit')
        );

        $this->table(['UUID', 'Adapter', 'Status', 'Processed', 'Failed', 'Created'], array_map(
            static fn(array $job): array => [
                (string) $job['uuid'],
                (string) $job['adapter'],
                (string) $job['status'],
                (string) $job['processed_records'],
                (string) $job['failed_records'],
                (string) ($job['created_at'] ?? ''),
            ],
            $jobs
        ));

        return self::SUCCESS;
    }
}
