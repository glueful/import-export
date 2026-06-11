<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

final class CsvReader
{
    /** @return \Generator<int,array<string,string|null>> */
    public function read(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open CSV file "%s".', $path));
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '\\');
            if ($header === false) {
                return;
            }

            /** @var list<string> $columns */
            $columns = array_map(static fn ($value): string => (string) $value, $header);

            while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $record = [];
                foreach ($columns as $index => $column) {
                    $record[$column] = $row[$index] ?? null;
                }

                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
}
