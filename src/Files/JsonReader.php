<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

final class JsonReader
{
    /** @return \Generator<int,array<string,mixed>> */
    public function read(string $path): \Generator
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read JSON file "%s".', $path));
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON import file must contain an object or array.');
        }

        if (array_is_list($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('JSON import array must contain objects.');
                }

                yield $row;
            }

            return;
        }

        yield $decoded;
    }
}
