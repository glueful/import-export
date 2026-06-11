<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Registry;

use Glueful\Extensions\ImportExport\Contracts\ExporterInterface;

final class ExporterRegistry
{
    /** @var array<string,ExporterInterface> */
    private array $exporters = [];

    /**
     * @param iterable<ExporterInterface> $exporters
     */
    public function __construct(iterable $exporters)
    {
        foreach ($exporters as $exporter) {
            $key = $exporter->key();
            if (isset($this->exporters[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate exporter key "%s"', $key));
            }

            $this->exporters[$key] = $exporter;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->exporters[$key]);
    }

    public function get(string $key): ExporterInterface
    {
        if (!isset($this->exporters[$key])) {
            throw new \RuntimeException(sprintf('No exporter registered for key "%s"', $key));
        }

        return $this->exporters[$key];
    }

    /** @return list<ExporterInterface> */
    public function all(): array
    {
        return array_values($this->exporters);
    }
}
