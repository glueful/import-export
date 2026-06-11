<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

final class CsvWriter
{
    /**
     * @param iterable<array<string,mixed>> $rows
     */
    public function write(string $path, iterable $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to write CSV file "%s".', $path));
        }

        try {
            $headerWritten = false;
            foreach ($rows as $row) {
                if (!$headerWritten) {
                    fputcsv($handle, array_keys($row), ',', '"', '\\');
                    $headerWritten = true;
                }

                fputcsv($handle, array_values($row), ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }
    }
}
