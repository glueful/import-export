<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

final class NdjsonReader
{
    /** @return \Generator<int,array<string,mixed>> */
    public function read(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open NDJSON file "%s".', $path));
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException(sprintf('Invalid NDJSON on line %d: %s', $lineNumber, $e->getMessage()), 0, $e);
                }

                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new \RuntimeException(sprintf('Invalid NDJSON on line %d: expected object.', $lineNumber));
                }

                yield $decoded;
            }
        } finally {
            fclose($handle);
        }
    }
}
