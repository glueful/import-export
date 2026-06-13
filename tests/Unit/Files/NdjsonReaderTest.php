<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Files;

use Glueful\Extensions\ImportExport\Files\JsonReader;
use Glueful\Extensions\ImportExport\Files\NdjsonReader;
use Glueful\Extensions\ImportExport\Files\NdjsonWriter;
use PHPUnit\Framework\TestCase;

final class NdjsonReaderTest extends TestCase
{
    public function testNdjsonReaderStreamsRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ndjson-reader-');
        self::assertIsString($path);
        file_put_contents($path, "{\"id\":1}\n{\"id\":2}\n");

        $rows = iterator_to_array((new NdjsonReader())->read($path));

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    public function testNdjsonReaderReportsMalformedLine(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ndjson-reader-');
        self::assertIsString($path);
        file_put_contents($path, "{\"id\":1}\nnot-json\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid NDJSON on line 2');

        iterator_to_array((new NdjsonReader())->read($path));
    }

    public function testNdjsonWriterWritesRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ndjson-writer-');
        self::assertIsString($path);

        (new NdjsonWriter())->write($path, [['id' => 1], ['id' => 2]]);

        $this->assertSame([['id' => 1], ['id' => 2]], iterator_to_array((new NdjsonReader())->read($path)));
    }

    public function testJsonReaderReadsArrayOrSingleObject(): void
    {
        $arrayPath = tempnam(sys_get_temp_dir(), 'json-reader-');
        $objectPath = tempnam(sys_get_temp_dir(), 'json-reader-');
        self::assertIsString($arrayPath);
        self::assertIsString($objectPath);
        file_put_contents($arrayPath, '[{"id":1},{"id":2}]');
        file_put_contents($objectPath, '{"id":3}');

        $this->assertSame([['id' => 1], ['id' => 2]], iterator_to_array((new JsonReader())->read($arrayPath)));
        $this->assertSame([['id' => 3]], iterator_to_array((new JsonReader())->read($objectPath)));
    }

    public function testJsonReaderRejectsFilesOverConfiguredByteLimit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'json-reader-');
        self::assertIsString($path);
        file_put_contents($path, '[{"id":1}]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON file exceeds');

        iterator_to_array((new JsonReader(maxBytes: 4))->read($path));
    }
}
