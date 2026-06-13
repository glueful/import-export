<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

use Glueful\Extensions\ImportExport\Support\PathGuard;

final class ZipBundleReader
{
    public function __construct(
        private int $maxEntries = 1000,
        private int $maxUncompressedBytes = 104857600,
        private int $maxEntryBytes = 52428800,
    ) {
    }

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
        $entryCount = 0;
        $totalUncompressedBytes = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $normalized = PathGuard::normalizeRelative($name);
                $entryCount++;
                if ($entryCount > $this->maxEntries) {
                    throw new \RuntimeException(sprintf(
                        'ZIP bundle has too many entries; maximum is %d.',
                        $this->maxEntries
                    ));
                }

                $size = $this->entryUncompressedSize($zip, $i, $name);
                if ($size > $this->maxEntryBytes) {
                    throw new \RuntimeException(sprintf(
                        'ZIP entry "%s" uncompressed size exceeds the maximum of %d bytes.',
                        $name,
                        $this->maxEntryBytes
                    ));
                }

                $totalUncompressedBytes += $size;
                if ($totalUncompressedBytes > $this->maxUncompressedBytes) {
                    throw new \RuntimeException(sprintf(
                        'ZIP bundle uncompressed size exceeds the maximum of %d bytes.',
                        $this->maxUncompressedBytes
                    ));
                }

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

    private function entryUncompressedSize(\ZipArchive $zip, int $index, string $name): int
    {
        $stat = $zip->statIndex($index);
        if (!is_array($stat)) {
            throw new \RuntimeException(sprintf('Unable to inspect ZIP entry "%s".', $name));
        }

        return (int) $stat['size'];
    }
}
