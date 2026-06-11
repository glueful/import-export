# Import Export Extension for Glueful

## Overview

Import Export is a general import/export engine for Glueful applications. It provides the infrastructure for CSV, JSON, NDJSON, and ZIP bundle jobs while domain adapters decide how records map to your application.

The extension owns orchestration: jobs, batches, queues, reports, retries, file helpers, and management APIs. Your app or product owns the domain adapters for content, products, users, orders, or any other records.

## Features

- **Importer and exporter registries**: collect domain adapters through service tags.
- **Job tracking**: stores jobs, batches, files, row errors, and reports.
- **Queue-backed processing**: creates deterministic batches and queues batch jobs.
- **Engine-owned retry**: queue jobs do not auto-redeliver non-idempotent work.
- **HTTP management API**: create, inspect, cancel, retry, and report jobs.
- **CLI management**: run imports/exports, list jobs, inspect status, retry, cancel, and clean up.
- **Streaming file helpers**: CSV, JSON, NDJSON, and ZIP bundle readers/writers.
- **Archive safety**: path guards block ZIP-slip style archive paths.
- **Failed-record support**: stores capped row errors and can export failed-record reports.
- **Retention cleanup**: removes old temporary files for terminal jobs.

## Installation

### Installation (Recommended)

Install via Composer:

```bash
composer require glueful/import-export

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but installing does not auto-enable them. Enable the provider:

```bash
php glueful extensions:enable import-export
php glueful extensions:cache
```

Run migrations:

```bash
php glueful migrate:run
```

### Local Development Installation

To develop the extension locally, register it as a Composer path repository in your app's `composer.json`, then require and enable it:

```jsonc
"repositories": [
    { "type": "path", "url": "extensions/import-export", "options": { "symlink": true } }
]
```

```bash
composer require glueful/import-export:@dev
php glueful extensions:enable import-export
php glueful migrate:run
```

Entries in `config/extensions.php` are plain string FQCNs. Prefer `extensions:enable` over editing by hand.

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info import-export
php glueful extensions:diagnose
```

Post-install checklist:

- Run migrations.
- Register at least one importer or exporter adapter.
- Confirm the adapter appears in `GET /import-export/adapters`.
- Confirm queue workers are running for the configured queue.

## Configuration

Configuration is loaded from `config/import_export.php` and merged under the `import_export` key.

| Key | Purpose |
| --- | --- |
| `enabled` | Extension-level enable flag. |
| `routes_enabled` | Set to `false` for service/CLI-only installs. |
| `source_disk` | Default disk for import sources. |
| `result_disk` | Default disk for export/report outputs. |
| `tmp_disk` / `tmp_path` | Temporary working area. |
| `queue` | Queue name used for batch jobs. |
| `batch_size` | Default records per batch. |
| `max_file_size` | Maximum accepted source size. |
| `retention_days` | Default cleanup horizon. |
| `error_cap_per_severity` | Stored error cap before overflow counting starts. |
| `stale_lock_minutes` | Batch lock staleness window. |

## Adapters

This extension does not know your domain model. A CMS, commerce app, or back office system should implement its own adapters for WordPress, Markdown, products, customers, orders, or other domain records.

Import/export adapters are domain-owned integrations that plug into this package's engine. The engine owns orchestration; adapters own domain translation.

### Engine Responsibilities

- Register importers and exporters through service tags.
- Create jobs, batches, file records, errors, and reports.
- Queue batch jobs.
- Claim batches atomically before processing.
- Prevent queue auto-retry from becoming the retry policy.
- Record adapter-reported errors with the configured per-severity cap.
- Roll batch progress up to the parent job.
- Stop processing cancelled jobs.
- Expose HTTP and CLI management APIs.

### Adapter Responsibilities

Importers implement `ImporterInterface`:

- `key()` returns a stable machine key.
- `label()` returns a human-readable label.
- `supports()` checks whether the provided source can be imported.
- `plan()` inspects the source and returns total records plus deterministic batches.
- `process()` handles one claimed batch and returns processed/failed counts plus row errors.

Exporters implement `ExporterInterface`:

- `key()` returns a stable machine key.
- `label()` returns a human-readable label.
- `plan()` returns total records plus deterministic batches.
- `process()` handles one claimed batch and returns processed/failed counts, errors, and optionally a result path.

Adapters should not create jobs, mutate engine tables directly, dispatch queue jobs, or decide global retry behavior.

### Retry And Idempotency

Retry is explicit and engine-owned. Queue jobs catch failures and return without throwing, so the queue worker does not redeliver non-idempotent batches automatically.

Adapters that support explicit retry must implement `RetryableAdapterInterface` and return `true` from `retryable()`.

Retryable adapters should make `process()` idempotent per batch or safely detect already-applied records. Non-idempotent adapters should not implement the retry capability.

### Service Tags

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

## Usage

### Service API

Use `ImportExportService` directly when another service owns the workflow.

```php
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

$job = $imports->createImport(
    'products',
    new ImportSource('uploads', 'imports/products.csv', 'text/csv'),
    new ImportOptions(mode: 'dry_run', batchSize: 500, actorUuid: $userUuid)
);
```

Exports use `createExport()` with `ExportOptions`.

### HTTP API

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

### CLI

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

## Queue And Retry Behavior

Batch jobs are deliberately never-throw. They catch failures and mark work failed through the engine instead of relying on queue worker auto-redelivery.

Explicit retry is available only for adapters that implement `RetryableAdapterInterface`. Retryable adapters should make `process()` idempotent per batch or safely detect records already applied.

## Reports And Retention

The report builder summarizes job type, adapter, status, totals, failed counts, overflow counts, and stored errors.

Failed-record exports are generated from stored row errors. The engine caps stored errors by severity and increments `error_overflow_count` after the cap is reached.

The retention cleaner deletes temporary files for terminal jobs older than the configured cutoff. It only removes files recorded with the `tmp` role; source and result files are not deleted by default.

## Security

### Archive Safety

ZIP bundle readers and writers use `PathGuard` to block unsafe paths:

- Absolute paths.
- Parent-directory traversal.
- Empty paths.
- Paths containing NUL bytes.
- Windows drive paths.
- Windows UNC paths.

This prevents ZIP-slip style extraction into unintended locations.

### Adapter Trust Boundary

Adapters run inside the application process. They should validate source structure, enforce domain permissions before writing records, and avoid shelling out to user-controlled paths.

The engine records errors and progress, but it does not validate domain-specific fields, content models, prices, users, or publishing rules.

### Permissions

The HTTP API uses the `import_export_permission` route middleware and these permission slugs:

- `import_export.view`
- `import_export.run_import`
- `import_export.run_export`
- `import_export.cancel`
- `import_export.retry`

If no permission manager is available, the guard fails closed with HTTP 403.

### Error Data

Stored row errors may contain excerpts or identifiers from imported data. Adapters should avoid putting secrets, access tokens, or full sensitive records into error contexts.

## Requirements

- PHP 8.3 or higher
- Glueful 1.54.0 or higher
- A configured queue worker for asynchronous batch processing

## License

MIT — licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
