<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Services\RetentionCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function config;

#[AsCommand(name: 'import-export:cleanup', description: 'Clean old temporary import/export files')]
final class ImportExportCleanupCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Delete temporary files older than this many days (default: import_export.retention_days config)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('days');
        $days = is_scalar($option) && (string) $option !== ''
            ? (int) $option
            : (int) config($this->getContext(), 'import_export.retention_days', 30);
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)) ?: time());
        $deleted = $this->getService(RetentionCleaner::class)->cleanOlderThan($cutoff);
        $this->success(sprintf('Deleted %d temporary file(s).', $deleted));

        return self::SUCCESS;
    }
}
