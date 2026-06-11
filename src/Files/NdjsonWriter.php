<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

final class NdjsonWriter
{
    /**
     * @param iterable<array<string,mixed>> $rows
     */
    public function write(string $path, iterable $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to write NDJSON file "%s".', $path));
        }

        try {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            }
        } finally {
            fclose($handle);
        }
    }
}
