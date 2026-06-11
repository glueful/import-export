<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Files;

use Glueful\Extensions\ImportExport\Files\CsvReader;
use Glueful\Extensions\ImportExport\Files\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    public function testCsvReaderStreamsRowsUsingHeader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-reader-');
        self::assertIsString($path);
        file_put_contents($path, "title,status\nFirst,draft\nSecond,published\n");

        $rows = iterator_to_array((new CsvReader())->read($path));

        $this->assertSame([
            ['title' => 'First', 'status' => 'draft'],
            ['title' => 'Second', 'status' => 'published'],
        ], $rows);
    }

    public function testCsvWriterWritesHeaderAndRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-writer-');
        self::assertIsString($path);

        (new CsvWriter())->write($path, [
            ['title' => 'First', 'status' => 'draft'],
            ['title' => 'Second', 'status' => 'published'],
        ]);

        $this->assertStringContainsString("title,status\n", (string) file_get_contents($path));
        $this->assertCount(2, iterator_to_array((new CsvReader())->read($path)));
    }
}
