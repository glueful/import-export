<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Console\Concerns\ParsesJsonOption;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:run', description: 'Create and queue an import job')]
final class ImportCreateCommand extends BaseCommand
{
    use ParsesJsonOption;

    protected function configure(): void
    {
        $this->addOption('adapter', null, InputOption::VALUE_REQUIRED, 'Importer adapter key');
        $this->addOption('disk', null, InputOption::VALUE_REQUIRED, 'Source storage disk', 'uploads');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Source path');
        $this->addOption('mime-type', null, InputOption::VALUE_REQUIRED, 'Source MIME type');
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Import mode: dry_run|commit', 'dry_run');
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size', '500');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor user UUID');
        $this->addOption('options', null, InputOption::VALUE_REQUIRED, 'Adapter options as JSON object');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $job = $this->getService(ImportExportService::class)->createImport(
                $this->requiredOption($input, 'adapter'),
                new ImportSource(
                    $this->stringOption($input, 'disk', 'uploads'),
                    $this->requiredOption($input, 'path'),
                    $this->stringOption($input, 'mime-type') !== '' ? $this->stringOption($input, 'mime-type') : null
                ),
                new ImportOptions(
                    $this->stringOption($input, 'mode', 'dry_run'),
                    $this->intOption($input, 'batch-size', 500),
                    $this->stringOption($input, 'actor') !== '' ? $this->stringOption($input, 'actor') : null,
                    $this->jsonOption($input, 'options')
                )
            );

            $this->success(sprintf('Import job queued: %s', $job['uuid']));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $this->stringOption($input, $name);
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('--%s is required.', $name));
        }

        return $value;
    }
}
