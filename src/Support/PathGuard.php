<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Support;

final class PathGuard
{
    public static function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new \RuntimeException(sprintf('Unsafe archive path "%s".', $path));
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    throw new \RuntimeException(sprintf('Unsafe archive path "%s".', $path));
                }

                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        if ($parts === []) {
            throw new \RuntimeException(sprintf('Unsafe archive path "%s".', $path));
        }

        return implode('/', $parts);
    }

    public static function resolveUnderRoot(string $root, string $relativePath): string
    {
        $normalized = self::normalizeRelative($relativePath);
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $targetDirectory = dirname($target);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $targetDirectory));
        }

        $realRoot = realpath($root);
        $realDirectory = realpath($targetDirectory);
        if ($realRoot === false || $realDirectory === false || !str_starts_with($realDirectory, $realRoot)) {
            throw new \RuntimeException(sprintf('Unsafe archive path "%s".', $relativePath));
        }

        return $target;
    }
}
