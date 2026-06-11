<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ImportExport\Console\Concerns\ParsesJsonOption;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function config;

#[AsCommand(name: 'export:run', description: 'Create and queue an export job')]
final class ExportCreateCommand extends BaseCommand
{
    use ParsesJsonOption;

    protected function configure(): void
    {
        $this->addOption('adapter', null, InputOption::VALUE_REQUIRED, 'Exporter adapter key');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format', 'ndjson');
        $this->addOption(
            'batch-size',
            null,
            InputOption::VALUE_REQUIRED,
            'Batch size (default: import_export.batch_size config)'
        );
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor user UUID');
        $this->addOption('filters', null, InputOption::VALUE_REQUIRED, 'Export filters as JSON object');
        $this->addOption('options', null, InputOption::VALUE_REQUIRED, 'Adapter options as JSON object');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $job = $this->getService(ImportExportService::class)->createExport(
                $this->requiredOption($input, 'adapter'),
                new ExportOptions(
                    $this->stringOption($input, 'format', 'ndjson'),
                    $this->intOption(
                        $input,
                        'batch-size',
                        (int) config($this->getContext(), 'import_export.batch_size', 500)
                    ),
                    $this->stringOption($input, 'actor') !== '' ? $this->stringOption($input, 'actor') : null,
                    $this->jsonOption($input, 'filters'),
                    $this->jsonOption($input, 'options')
                )
            );

            $this->success(sprintf('Export job queued: %s', $job['uuid']));

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
