<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

use Glueful\Extensions\ImportExport\Support\PathGuard;

final class ZipBundleReader
{
    /** @return list<string> */
    public function extract(string $path, string $destination): array
    {
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException(sprintf('Unable to create extraction directory "%s".', $destination));
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException(sprintf('Unable to open ZIP bundle "%s".', $path));
        }

        $files = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $normalized = PathGuard::normalizeRelative($name);
                $target = PathGuard::resolveUnderRoot($destination, $normalized);
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new \RuntimeException(sprintf('Unable to read ZIP entry "%s".', $name));
                }

                file_put_contents($target, $contents);
                $files[] = $normalized;
            }
        } finally {
            $zip->close();
        }

        sort($files);

        return $files;
    }
}
