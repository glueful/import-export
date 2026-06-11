<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Unit\Registry;

use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use PHPUnit\Framework\TestCase;

final class ImporterRegistryTest extends TestCase
{
    public function testFindsImporterByKey(): void
    {
        $importer = new FakeImporter('wordpress');
        $registry = new ImporterRegistry([$importer]);

        $this->assertSame($importer, $registry->get('wordpress'));
        $this->assertSame([$importer], $registry->all());
    }

    public function testDuplicateImporterKeysThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate importer key');

        new ImporterRegistry([
            new FakeImporter('wordpress'),
            new FakeImporter('wordpress'),
        ]);
    }

    public function testMissingImporterThrowsUsefulException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No importer registered for key "missing"');

        (new ImporterRegistry([]))->get('missing');
    }

    public function testEmptyRegistryIsValid(): void
    {
        $registry = new ImporterRegistry([]);

        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->has('wordpress'));
    }
}

final class FakeImporter implements ImporterInterface
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
        return 'Fake Importer';
    }

    public function supports(ImportSource $source): bool
    {
        return true;
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        return new ImportPlan(totalRecords: 0, batches: [], retryable: true);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        return new ImportBatchResult(processedRecords: 0, failedRecords: 0, errors: []);
    }
}
