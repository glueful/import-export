# Glueful Import Export Extension Spec

**Status:** Draft v2  
**Package:** `glueful/import-export`  
**Purpose:** Generic import/export engine for Glueful apps.
**Framework floor:** `glueful/framework >=1.54.0`

## Summary

`glueful/import-export` provides the reusable machinery for import and export jobs:

- file readers and writers;
- batching;
- queue integration;
- validation reports;
- progress tracking;
- dry runs;
- export bundles;
- retry/failure accounting.

It is not a CMS importer, ecommerce importer, or analytics product. Domain packages provide adapters that map their own data into their own models.

For Lemma, this means:

- `glueful/import-export` runs the job;
- Lemma provides adapters such as WordPress, Markdown/MDX, and CSV-to-entry mapping.

## Boundary

### Core Should Own

No broad core seam is required initially.

Core already provides:

- queue;
- queue job base class and `JobInterface`;
- database queue tables including `queue_jobs`, `queue_failed_jobs`, and `queue_batches`;
- failed-job storage/retry primitives;
- scheduler;
- storage/uploads;
- database;
- validation primitives;
- OpenAPI/docs;
- webhooks/events;
- locks.

The extension can depend on those framework primitives directly.

A small core seam may be considered later only if multiple extensions need to discover import/export providers without depending on this package. Until then, keep the contracts inside this extension.

### Extension Should Own

`glueful/import-export` owns:

- job lifecycle;
- importer/exporter registry;
- file format readers and writers;
- import/export storage;
- queue jobs;
- validation report storage;
- progress APIs;
- dry-run mode;
- resumable batch processing;
- export bundle packaging;
- CLI commands.

Framework facts this spec relies on:

- `QueueManager::push(string $job, array $data = [], ?string $queue = null, ?string $connection = null)` queues jobs and returns a UUID.
- `QueueManager::bulk()` can enqueue multiple job definitions.
- Queue jobs should extend `Glueful\Queue\Job` or implement `Glueful\Queue\Contracts\JobInterface`.
- Core database queue migrations already include `queue_batches`; import/export may use core batch UUIDs for worker grouping, but still owns domain job/batch/report tables below.
- Extension service definitions can declare `tags`, and typed providers can expose static `tags()`.
- Extension providers can declare permissions through `ServiceProvider::permissions()`.

Release sequencing:

- v1 can ship as a standalone extension on `glueful/framework >=1.54.0`.
- No framework release is required because import/export contracts live inside this extension.

### Domain Packages Should Own

Domain packages own adapters:

- Lemma owns WordPress -> Lemma entries.
- Lemma owns Markdown/MDX -> Lemma pages/entries.
- Lemma owns CSV columns -> selected Lemma content model.
- Commerce owns product/customer/order importers.
- Users owns user importers, if needed.

The engine should not know what a "post", "product", "tenant", or "entry" means.

## Engine vs Adapter

The engine answers:

- Where is the file?
- Which adapter should process it?
- How is it batched?
- How is progress tracked?
- Where are validation errors stored?
- Should this run as dry-run or commit?
- How are failures retried?
- How is the export bundle written?

The adapter answers:

- How is the source parsed into domain records?
- How does a source record map to domain data?
- What validations are domain-specific?
- What conflicts are possible?
- How should relationships be resolved?
- How should records be committed?

Concrete Lemma flow:

1. User uploads `wordpress-export.zip`.
2. Lemma creates an import job using `glueful/import-export`.
3. The engine stores the file, creates a job row, and queues batches.
4. Lemma's `WordPressImporter` parses posts, media, taxonomies, and authors.
5. The engine records progress and validation errors.
6. Lemma maps valid records into content models, entries, blobs, taxonomies, and routes.
7. The engine marks the job complete and exposes the report.

## Contracts

Primary adapter contracts live in the extension:

```php
namespace Glueful\Extensions\ImportExport\Contracts;

interface ImporterInterface
{
    public function key(): string;

    public function label(): string;

    public function supports(ImportSource $source): bool;

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan;

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult;
}

interface ExporterInterface
{
    public function key(): string;

    public function label(): string;

    public function plan(ExportOptions $options): ExportPlan;

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult;
}
```

Provider registration should use an additive tagged-iterator pattern:

- `import_export.importer`
- `import_export.exporter`

Domain extensions register adapters through DI services and tags. The import-export extension collects them into registries.

Extension registration examples:

```php
public static function services(): array
{
    return [
        WordPressImporter::class => [
            'class' => WordPressImporter::class,
            'shared' => true,
            'autowire' => true,
            'tags' => [['name' => 'import_export.importer', 'priority' => 0]],
        ],
    ];
}
```

The structured tag form above is supported by `ContainerFactory::applyDslTags()`. A plain string tag is also valid when no priority is needed:

```php
'tags' => ['import_export.importer']
```

Typed providers may alternatively expose `static tags()` if they use `defs()`.

## Data Model

Suggested tables:

```text
import_export_jobs
import_export_batches
import_export_files
import_export_errors
import_export_reports
```

### `import_export_jobs`

Fields:

- `id` bigint primary auto-increment.
- `uuid` string(12), unique.
- `type` string(10), `import` or `export`.
- `adapter` string(120), indexed.
- `status` string(20), indexed.
- `mode` string(20), `dry_run` or `commit`.
- `source_disk` string(120) nullable.
- `source_path` string(2048) nullable.
- `result_disk` string(120) nullable.
- `result_path` string(2048) nullable.
- `total_records` integer default 0.
- `processed_records` integer default 0.
- `failed_records` integer default 0.
- `error_overflow_count` integer default 0.
- `created_by` string(12) nullable, indexed, no cross-package foreign key to users.
- `started_at` timestamp nullable.
- `finished_at` timestamp nullable.
- `created_at` timestamp nullable.
- `updated_at` timestamp nullable.

Indexes:

- unique `uuid`.
- index `type`.
- index `status`.
- index `adapter`.
- index `created_by`.
- index `created_at`.

### `import_export_batches`

Fields:

- `id` bigint primary auto-increment.
- `uuid` string(12), unique.
- `job_uuid` string(12), indexed, references `import_export_jobs.uuid` in code, no DB foreign key.
- `sequence` integer.
- `status` string(20), indexed.
- `offset` integer default 0.
- `limit` integer default 0.
- `processed_records` integer default 0.
- `failed_records` integer default 0.
- `attempts` integer default 0.
- `locked_at` timestamp nullable.
- `started_at` timestamp nullable.
- `finished_at` timestamp nullable.
- `created_at` timestamp nullable.
- `updated_at` timestamp nullable.

Indexes:

- unique `uuid`.
- unique `(job_uuid, sequence)`.
- index `(job_uuid, status, sequence)`.
- index `locked_at`.

### `import_export_files`

Fields:

- `id` bigint primary auto-increment.
- `uuid` string(12), unique.
- `job_uuid` string(12), indexed, references `import_export_jobs.uuid` in code, no DB foreign key.
- `role` string(20), `source`, `result`, `report`, or `failed_records`.
- `disk` string(120).
- `path` string(2048).
- `mime_type` string(127) nullable.
- `size` bigint default 0.
- `checksum` string(128) nullable.
- `created_at` timestamp nullable.

### `import_export_errors`

Fields:

- `id` bigint primary auto-increment.
- `uuid` string(12), unique.
- `job_uuid` string(12), indexed, references `import_export_jobs.uuid` in code, no DB foreign key.
- `batch_uuid` string(12) nullable, indexed, references `import_export_batches.uuid` in code, no DB foreign key.
- `record_key` string(255) nullable.
- `row_number` integer nullable.
- `severity` string(20), indexed.
- `code` string(120).
- `message` text.
- `details` json nullable.
- `created_at` timestamp nullable.

Error rows must be capped:

- store the first N errors per severity per job;
- default cap: 1000 per severity;
- increment `import_export_jobs.error_overflow_count` for suppressed additional errors;
- include overflow totals in reports.

### `import_export_reports`

Fields:

- `id` bigint primary auto-increment.
- `uuid` string(12), unique.
- `job_uuid` string(12), indexed, references `import_export_jobs.uuid` in code, no DB foreign key.
- `summary` json.
- `metrics` json nullable.
- `created_at` timestamp nullable.

Migration notes:

- Register migrations with source `glueful/import-export`.
- Use the default migration tier unless another extension table is referenced.
- Do not create DB foreign keys to user, tenant, or domain-package tables.

## Supported Formats

Initial readers/writers:

- CSV;
- JSON;
- NDJSON;
- ZIP bundle wrapper.

Format handling should be streaming where possible. Large files must not be read entirely into memory.

ZIP handling must defend against zip-slip:

- validate every archive entry name before extraction;
- reject absolute paths, drive-letter paths, `..` traversal, empty names, and path separators that normalize outside the extraction root;
- use `Glueful\Storage\PathGuard` or equivalent normalized path checks;
- never trust archive entry names when writing to `tmp_disk`.

## Job Modes

### Dry Run

Dry run parses and validates without committing domain writes.

Dry run should produce:

- record count;
- validation errors;
- relationship/conflict warnings;
- preview report;
- estimated commit shape.

### Commit

Commit performs writes through the adapter.

Commit should:

- process in batches;
- use transactions around safe batch units;
- record errors without losing the whole job when possible;
- support retrying failed batches;
- require adapter idempotency for retryable adapters.

Retry contract:

- retry re-delivers the whole batch;
- retryable adapters must be idempotent per record, usually by upserting with a stable source key;
- adapters that cannot guarantee idempotency must declare themselves non-retryable;
- the engine must refuse retry for non-retryable adapters after a partial failure.

## Batch Claiming And Cancellation

Batch workers must claim work with a conditional update that changes a column, not with a read-then-write race.

Pattern:

- select candidate batches by `status = pending` or stale `locked_at`;
- claim by updating `status`, `locked_at`, and `attempts` with a WHERE condition that still matches the unclaimed/stale state;
- treat only changed rows as claimed, and be careful with databases that report matched rows rather than changed rows;
- reclaim stale locks using a configured `locked_at` cutoff;
- observe cancellation at batch boundaries: an in-flight batch may finish, but the engine must check job status before claiming the next batch.

## Public API

Suggested routes:

```text
GET    /import-export/adapters
POST   /import-export/imports
POST   /import-export/exports
GET    /import-export/jobs
GET    /import-export/jobs/{uuid}
GET    /import-export/jobs/{uuid}/errors
GET    /import-export/jobs/{uuid}/report
POST   /import-export/jobs/{uuid}/cancel
POST   /import-export/jobs/{uuid}/retry
```

Suggested permissions:

- `import_export.view`
- `import_export.run_import`
- `import_export.run_export`
- `import_export.cancel`
- `import_export.retry`

Declare these through `ImportExportServiceProvider::permissions()` using `Glueful\Permissions\Catalog\Permission::define()`.

Permission enforcement:

- Use an extension-owned route guard/middleware that calls `Glueful\Permissions\PermissionManager::can()` directly.
- Fail closed when the user is missing, the permission manager is unavailable, or permission is denied.
- Do not rely on declarative catalog registration alone; declaration is not enforcement.
- Do not rely on `gate_permissions`/`#[RequiresPermission]` unless the package raises its framework floor to a version where route handler metadata permission enforcement is verified.

## CLI

Suggested commands:

```text
php glueful import:list
php glueful import:run <adapter> <file> [--dry-run]
php glueful export:list
php glueful export:run <adapter> [--output=]
php glueful import-export:status <job>
php glueful import-export:retry <job>
```

## Events

Emit events for:

- job created;
- job started;
- batch completed;
- batch failed;
- job completed;
- job failed;
- job cancelled.

These events allow webhooks, notifications, and audit/activity logging without coupling.

Events should extend `Glueful\Events\Contracts\BaseEvent` and be dispatched through `Glueful\Events\EventService`.

## Storage

Use Glueful storage disks for:

- source files;
- result files;
- reports;
- temporary extraction output.

Config should allow separate disks:

```php
return [
    'source_disk' => 'uploads',
    'result_disk' => 'uploads',
    'tmp_disk' => 'local',
    'batch_size' => 500,
    'max_file_size' => 52428800,
    'retention_days' => 30,
    'error_cap_per_severity' => 1000,
];
```

Retention and cleanup:

- default job/report retention is 30 days;
- source/result retention follows the file role and config;
- temporary extraction output must be deleted on completion, failure, and cancellation;
- cleanup should be available through a command and may be scheduled by the app.

## Lemma Adapters

Lemma should provide adapters such as:

- `lemma.wordpress`
- `lemma.markdown`
- `lemma.mdx`
- `lemma.csv_entries`

Those adapters should handle:

- content model selection;
- field mapping;
- taxonomy mapping;
- author mapping;
- slug/route conflict checks;
- media download/import to blobs;
- draft/published status;
- localized fields when enabled.

The import-export engine only runs and observes the job.

## Testing Requirements

- CSV reader streams rows.
- JSON and NDJSON readers handle malformed input safely.
- Dry run records validation errors without committing writes.
- Commit mode processes batches and updates progress.
- Failed batch can be retried.
- Cancelled job stops future batches.
- Export writes a result file and report.
- Adapter registry collects tagged importers/exporters.
- Permission checks protect routes.
- Large file import does not load the entire file into memory.
- ZIP extraction rejects path traversal entries.
- Batch claiming uses conditional updates and handles stale locks.
- Cancellation stops future batch claims.
- Retry refuses non-idempotent adapters.
- Error row cap records overflow counts.
- Failed-record export produces CSV or NDJSON output.

## Decisions

1. **No core seam initially.** Contracts live inside the extension.
2. **Adapter tags are verified.** DSL supports structured tag arrays and plain strings.
3. **ZIP extraction must use path validation.** No archive entry can write outside the extraction root.
4. **Batch claim is conditional-update based.** No read-then-write race; stale locks can be reclaimed.
5. **Retry requires idempotent adapters.** Non-idempotent adapters are non-retryable.
6. **Error rows are capped.** Overflow counts are tracked on the job/report.
7. **Retention is config-driven.** Default 30 days, with temp extraction cleanup on all terminal states.
8. **Failed-record export is v1.** Export failed rows as CSV or NDJSON for correction and re-import.
9. **Mapping UI belongs to domain apps.** Lemma owns CMS-specific mapping UI.
10. **Permission enforcement is extension-owned.** Routes call `PermissionManager::can()` through a guard, not just catalog declarations.

## Open Questions

None outstanding.
