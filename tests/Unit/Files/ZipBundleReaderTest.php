<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Files;

use Glueful\Extensions\ImportExport\Files\ZipBundleReader;
use Glueful\Extensions\ImportExport\Files\ZipBundleWriter;
use PHPUnit\Framework\TestCase;

final class ZipBundleReaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }
    }

    public function testZipBundleWriterAndReaderRoundTripFiles(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip-writer-') . '.zip';
        $extractTo = sys_get_temp_dir() . '/zip-reader-' . bin2hex(random_bytes(4));

        (new ZipBundleWriter())->write($zipPath, [
            'content/posts.ndjson' => "{\"id\":1}\n",
            'manifest.json' => '{"version":1}',
        ]);

        $files = (new ZipBundleReader())->extract($zipPath, $extractTo);

        $this->assertSame(['content/posts.ndjson', 'manifest.json'], $files);
        $this->assertSame("{\"id\":1}\n", file_get_contents($extractTo . '/content/posts.ndjson'));
    }

    public function testZipBundleReaderRejectsTraversalEntries(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip-reader-') . '.zip';
        $extractTo = sys_get_temp_dir() . '/zip-reader-' . bin2hex(random_bytes(4));
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('../escape.txt', 'nope');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe archive path');

        (new ZipBundleReader())->extract($zipPath, $extractTo);
    }

    public function testZipBundleReaderRejectsTooManyEntries(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip-reader-') . '.zip';
        $extractTo = sys_get_temp_dir() . '/zip-reader-' . bin2hex(random_bytes(4));
        (new ZipBundleWriter())->write($zipPath, [
            'one.txt' => 'one',
            'two.txt' => 'two',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many entries');

        (new ZipBundleReader(maxEntries: 1))->extract($zipPath, $extractTo);
    }

    public function testZipBundleReaderRejectsExcessiveUncompressedSize(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip-reader-') . '.zip';
        $extractTo = sys_get_temp_dir() . '/zip-reader-' . bin2hex(random_bytes(4));
        (new ZipBundleWriter())->write($zipPath, [
            'large.txt' => str_repeat('a', 16),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uncompressed size');

        (new ZipBundleReader(maxUncompressedBytes: 8))->extract($zipPath, $extractTo);
    }
}
