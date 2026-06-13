<?php

declare(strict_types=1);

namespace Glueful\Extensions\ImportExport\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Database\Migrations\CreateImportExportTables;
use Glueful\Helpers\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class ImportExportTestCase extends TestCase
{
    protected ApplicationContext $context;
    protected Connection $connection;

    /** @var array<string,mixed> */
    protected array $bindings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        if (class_exists(CreateImportExportTables::class)) {
            (new CreateImportExportTables())->up($this->connection->getSchemaBuilder());
        }

        $connection = $this->connection;
        $bindings = &$this->bindings;

        $container = new class ($connection, $bindings) implements ContainerInterface {
            /**
             * @param array<string,mixed> $bindings
             */
            public function __construct(
                private Connection $connection,
                private array &$bindings,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database'
                    || $id === Connection::class
                    || array_key_exists($id, $this->bindings);
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
    }

    protected function appContext(): ApplicationContext
    {
        return $this->context;
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }

    protected function bind(string $id, mixed $service): void
    {
        $this->bindings[$id] = $service;
    }

    protected function seedSourceFile(
        string $relativePath = 'imports/source.ndjson',
        string $contents = "{}\n",
        string $disk = 'uploads',
    ): string {
        $path = $this->context->getBasePath() . DIRECTORY_SEPARATOR
            . $disk . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException(sprintf('Unable to create test source directory "%s".', dirname($path)));
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array<string,mixed> $overrides */
    protected function seedJob(array $overrides = []): array
    {
        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'type' => 'import',
            'adapter' => 'fake',
            'status' => 'pending',
            'mode' => 'dry_run',
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
            'error_overflow_count' => 0,
        ], $overrides);

        $this->connection->table('import_export_jobs')->insert($row);

        return $row;
    }

    /** @param array<string,mixed> $overrides */
    protected function seedBatch(array $overrides = []): array
    {
        $jobUuid = $overrides['job_uuid'] ?? $this->seedJob()['uuid'];

        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'job_uuid' => $jobUuid,
            'sequence' => 1,
            'status' => 'pending',
            'offset' => 0,
            'limit' => 100,
            'processed_records' => 0,
            'failed_records' => 0,
            'attempts' => 0,
        ], $overrides);

        $this->connection->table('import_export_batches')->insert($row);

        return $row;
    }
}
