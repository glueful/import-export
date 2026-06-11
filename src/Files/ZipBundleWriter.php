<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Files;

use Glueful\Extensions\ImportExport\Support\PathGuard;

final class ZipBundleWriter
{
    /**
     * @param array<string,string> $files
     */
    public function write(string $path, array $files): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(sprintf('Unable to create ZIP bundle "%s".', $path));
        }

        try {
            foreach ($files as $relativePath => $contents) {
                $zip->addFromString(PathGuard::normalizeRelative($relativePath), $contents);
            }
        } finally {
            $zip->close();
        }
    }
}
