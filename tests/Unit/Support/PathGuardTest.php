<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Support;

use Glueful\Extensions\ImportExport\Support\PathGuard;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    public function testSafeRelativePathIsNormalized(): void
    {
        $this->assertSame('content/posts.ndjson', PathGuard::normalizeRelative('content/./posts.ndjson'));
    }

    /**
     * @dataProvider unsafePaths
     */
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe archive path');

        PathGuard::normalizeRelative($path);
    }

    /** @return list<array{string}> */
    public static function unsafePaths(): array
    {
        return [
            ['../escape.txt'],
            ['/tmp/escape.txt'],
            ['C:\\escape.txt'],
            ['content/../../escape.txt'],
            ["content/\0hidden.ndjson"],
        ];
    }
}
