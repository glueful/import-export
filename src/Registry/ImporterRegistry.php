<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Registry;

use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;

final class ImporterRegistry
{
    /** @var array<string,ImporterInterface> */
    private array $importers = [];

    /**
     * @param iterable<ImporterInterface> $importers
     */
    public function __construct(iterable $importers)
    {
        foreach ($importers as $importer) {
            $key = $importer->key();
            if (isset($this->importers[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate importer key "%s"', $key));
            }

            $this->importers[$key] = $importer;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->importers[$key]);
    }

    public function get(string $key): ImporterInterface
    {
        if (!isset($this->importers[$key])) {
            throw new \RuntimeException(sprintf('No importer registered for key "%s"', $key));
        }

        return $this->importers[$key];
    }

    /** @return list<ImporterInterface> */
    public function all(): array
    {
        return array_values($this->importers);
    }
}
