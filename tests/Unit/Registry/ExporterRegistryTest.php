<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Registry;

use Glueful\Extensions\ImportExport\Contracts\ExporterInterface;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportBatchResult;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;
use PHPUnit\Framework\TestCase;

final class ExporterRegistryTest extends TestCase
{
    public function testFindsExporterByKey(): void
    {
        $exporter = new FakeExporter('entries');
        $registry = new ExporterRegistry([$exporter]);

        $this->assertSame($exporter, $registry->get('entries'));
        $this->assertSame([$exporter], $registry->all());
    }

    public function testDuplicateExporterKeysThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate exporter key');

        new ExporterRegistry([
            new FakeExporter('entries'),
            new FakeExporter('entries'),
        ]);
    }

    public function testMissingExporterThrowsUsefulException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No exporter registered for key "missing"');

        (new ExporterRegistry([]))->get('missing');
    }

    public function testEmptyRegistryIsValid(): void
    {
        $registry = new ExporterRegistry([]);

        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->has('entries'));
    }
}

final class FakeExporter implements ExporterInterface
{
    public function __construct(private string $key)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake Exporter';
    }

    public function plan(ExportOptions $options): ExportPlan
    {
        return new ExportPlan(totalRecords: 0, batches: [], retryable: true);
    }

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult
    {
        return new ExportBatchResult(processedRecords: 0, failedRecords: 0, errors: [], resultPath: null);
    }
}
