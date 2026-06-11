# Usage

`glueful/import-export` is an engine package. Install it in a Glueful app, register domain adapters, then use the service, HTTP routes, or CLI commands to create and monitor jobs.

## Configuration

Defaults live in `config/import_export.php`.

Key settings:

- `routes_enabled`: set to `false` for service/CLI-only installs.
- `source_disk`: default disk for import sources.
- `result_disk`: default disk for export/report outputs.
- `tmp_disk` and `tmp_path`: temporary working area.
- `queue`: queue name used for batch jobs.
- `batch_size`: default records per batch.
- `retention_days`: default cleanup horizon.
- `error_cap_per_severity`: stored error cap before overflow counting starts.

## Service Tags

Adapters are collected through tagged services:

```php
return [
    App\Imports\ProductsImporter::class => [
        'class' => App\Imports\ProductsImporter::class,
        'shared' => true,
        'autowire' => true,
        'tags' => ['import_export.importer'],
    ],
];
```

Use `import_export.exporter` for exporters.

## Creating Jobs

Use `ImportExportService` directly when another service owns the workflow.

```php
$job = $imports->createImport(
    'products',
    new ImportSource('uploads', 'imports/products.csv', 'text/csv'),
    new ImportOptions(mode: 'dry_run', batchSize: 500, actorUuid: $userUuid)
);
```

Exports use `createExport()` with `ExportOptions`.

## HTTP API

Routes are mounted under `/import-export` when `routes_enabled` is true.

- `GET /import-export/adapters`
- `POST /import-export/imports`
- `POST /import-export/exports`
- `GET /import-export/jobs`
- `GET /import-export/jobs/{uuid}`
- `GET /import-export/jobs/{uuid}/errors`
- `GET /import-export/jobs/{uuid}/report`
- `POST /import-export/jobs/{uuid}/cancel`
- `POST /import-export/jobs/{uuid}/retry`

Management routes require auth plus the extension permission guard.

## CLI

Create jobs:

```bash
php glueful import:run --adapter=products --disk=uploads --path=imports/products.csv --mode=dry_run
php glueful export:run --adapter=products --format=ndjson
```

Inspect and manage jobs:

```bash
php glueful import:list
php glueful export:list
php glueful import-export:status <job-uuid>
php glueful import-export:retry <job-uuid>
php glueful import-export:cancel <job-uuid>
php glueful import-export:cleanup --days=30
```

## Queue Behavior

Batch jobs are deliberately never-throw. They catch failures and mark work failed through the engine instead of relying on queue worker auto-redelivery. Explicit retry is available only for adapters that implement `RetryableAdapterInterface`.

## Reports And Failed Records

The report builder summarizes job type, adapter, status, totals, failed counts, overflow counts, and stored errors.

Failed-record exports are generated from stored row errors. The engine caps stored errors by severity and increments `error_overflow_count` after the cap is reached.

## Retention

The retention cleaner deletes temporary files for terminal jobs older than the configured cutoff. It only removes files recorded with the `tmp` role; source and result files are not deleted by default.
