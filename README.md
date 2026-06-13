# Import Export Extension for Glueful

## Overview

Import Export is a general import/export engine for Glueful applications. It owns the
machinery every bulk data flow needs -- jobs, deterministic batches, queue dispatch,
claiming, progress roll-up, row errors, reports, retries, and management APIs -- while
knowing nothing about what the records mean.

Domain meaning lives in **adapters**. Your app (a CMS, a commerce back office, a CRM)
implements `ImporterInterface` / `ExporterInterface` for its own record types and
registers them through service tags. The engine never parses your content model, never
validates your prices, and never decides what a "post" is; it just runs the job safely.

- **Engine (this package):** job/batch/file/error/report persistence, queue-backed batch
  processing, atomic batch claiming with stale-lock reclaim, never-throw queue jobs,
  explicit engine-owned retry, error caps, lifecycle events, HTTP + CLI management,
  streaming file helpers, ZIP-slip protection.
- **Adapters (your code):** what a record is, how to read it from a source, how to write
  it to your domain, what counts as a row error.

## Features

- Importer and exporter registries collected through service tags (both tag forms).
- Job tracking: jobs, batches, files, capped row errors, and reports.
- Queue-backed processing with deterministic, adapter-planned batches.
- Conditional-UPDATE batch claiming with stale-lock reclaim.
- Never-throw queue jobs: adapter exceptions mark work failed instead of triggering
  queue auto-redelivery.
- Explicit, engine-owned retry restricted to adapters that declare themselves retryable.
- Dry-run and commit import modes.
- Seven lifecycle events (job created/started/completed/failed/cancelled, batch
  completed/failed).
- Streaming CSV, JSON, NDJSON, and ZIP bundle readers/writers.
- ZIP-slip protection via `PathGuard` (hostile-archive tested).
- Fail-closed permission gating on every HTTP route.
- HTTP management API and CLI commands.
- Failed-record export service and tmp-file retention cleanup.

## Installation

Install via Composer:

```bash
composer require glueful/import-export

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but installing does not
auto-enable them. Enable the provider and run migrations:

```bash
php glueful extensions:enable import-export
php glueful extensions:cache
php glueful migrate:run
```

### Local Development Installation

Register the extension as a Composer path repository in your app's `composer.json`,
then require and enable it:

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

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info import-export
php glueful extensions:diagnose
```

Post-install checklist:

- Run migrations (five `import_export_*` tables).
- Register at least one importer or exporter adapter.
- Confirm the adapter appears in `GET /import-export/adapters`.
- Confirm queue workers are running for the configured queue.

## Writing an Adapter

### Importer Contract

Implement `Glueful\Extensions\ImportExport\Contracts\ImporterInterface`:

| Method | Responsibility |
| --- | --- |
| `key(): string` | Stable machine key (used in API calls and job rows). |
| `label(): string` | Human-readable label for adapter listings. |
| `supports(ImportSource $source): bool` | Whether this source (disk, path, MIME type, metadata) can be imported. |
| `plan(ImportSource $source, ImportOptions $options): ImportPlan` | Inspect the source and return `totalRecords`, a deterministic list of `ImportBatch` windows (uuid, sequence, offset, limit), and whether the adapter is `retryable`. |
| `process(ImportBatch $batch, ImportContext $context): ImportBatchResult` | Handle one claimed batch window and return processed/failed counts plus row errors. |

### Exporter Contract

Implement `Glueful\Extensions\ImportExport\Contracts\ExporterInterface`:

| Method | Responsibility |
| --- | --- |
| `key(): string` / `label(): string` | As above. |
| `plan(ExportOptions $options): ExportPlan` | Return `totalRecords`, deterministic `ExportBatch` windows, and retryability. `format`, `filters`, and `options` are delivered here. |
| `process(ExportBatch $batch, ExportContext $context): ExportBatchResult` | Handle one claimed batch and return counts, errors, and optionally a `resultPath` (recorded as a result file on the job). |

### What process() Actually Receives

Be aware of what survives the queue round-trip. The engine persists only the batch
window (`uuid`, `sequence`, `offset`, `limit`) and the job row. At process time:

- `ImportContext` carries `jobUuid`, `mode` (`dry_run`/`commit`), and `actorUuid`. Its
  `options` array is currently always empty -- `ImportOptions::options` reaches `plan()`
  only.
- `ExportContext` carries `jobUuid` and `actorUuid`; its `format` is currently fixed to
  `ndjson` regardless of the requested format, and `ExportOptions::filters`/`options`
  reach `plan()` only.
- `ImportBatch`/`ExportBatch` `metadata` from your plan is not persisted and is empty at
  process time.

Adapters that need plan-time options, filters, or formats during `process()` must carry
them themselves (for example, encode them in a sidecar file, a domain table, or derive
them from the source again).

### Registration via Service Tags

Tag your adapter services with `import_export.importer` or `import_export.exporter` in
your extension's (or app provider's) `services()` definition. Both tag forms work:

```php
public static function services(): array
{
    return [
        // Plain-string tag form
        App\Imports\ProductsImporter::class => [
            'class' => App\Imports\ProductsImporter::class,
            'shared' => true,
            'autowire' => true,
            'tags' => ['import_export.importer'],
        ],

        // Object tag form with priority
        App\Exports\ProductsExporter::class => [
            'class' => App\Exports\ProductsExporter::class,
            'shared' => true,
            'autowire' => true,
            'tags' => [
                ['name' => 'import_export.exporter', 'priority' => 10],
            ],
        ],
    ];
}
```

Adapter keys must be unique; the registry rejects duplicate keys at construction.

Adapters should not create jobs, mutate `import_export_*` tables directly, dispatch
queue jobs, or decide global retry behavior -- that is the engine's job.

### Retryability and Idempotency Contract

Retry is explicit and engine-owned. To opt in, implement
`Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface` and return `true`
from `retryable()`.

The contract: **retry re-delivers the whole batch window.** When a job is retried, every
failed batch is reset to `pending` and pushed again in full -- including records that may
already have been applied before the batch failed midway. Retryable adapters therefore
MUST apply records idempotently: upsert by a stable source key (an external id, a slug,
a checksum), or detect and skip already-applied records.

If your adapter cannot make `process()` idempotent per batch, do not implement the
retry capability; the engine will refuse explicit retries for it.

### A Lemma Adapter Sketch

As a motivating example, a CMS like Lemma would ship its own adapter set in its own
package -- the engine stays domain-blind:

- a WordPress importer (key e.g. `lemma.wordpress`) that plans batches over a WXR
  archive and upserts posts by source GUID (retryable),
- a Markdown bundle importer/exporter (`lemma.markdown`) over a ZIP of front-mattered
  files, keyed by path,
- a CSV content exporter (`lemma.csv`) for spreadsheet round-trips.

Those adapters, their keys, and their mappings belong to Lemma; this package only runs
them.

## Job Lifecycle

### Statuses

`pending -> planning -> queued -> running -> completed | failed | cancelled`, with
`failed -> queued` reachable only through explicit retry. Transitions are validated;
invalid transitions are rejected (HTTP 422 on cancel).

### Creation

`createImport()` verifies `supports()`, calls the adapter's `plan()`, persists the job
(+ a `source` file row for imports), persists one batch row per planned batch, pushes
one queue job per batch onto the configured queue, and dispatches
`ImportExportJobCreated`. Imports default to `dry_run` mode; exports always run in
`commit` mode.

### Dry-Run vs Commit

The import `mode` is persisted on the job and delivered to `process()` through
`ImportContext::mode`. In `dry_run`, adapters must validate and count but not write
domain data; row errors are recorded either way, so a dry run doubles as a validation
report.

### Batch Claiming and Stale-Lock Reclaim

A worker claims a batch with a single conditional UPDATE that flips it to `running`,
always sets a fresh `locked_at`, increments `attempts`, and stamps `started_at`. The
claim succeeds for a `pending` batch, or for a `running` batch whose `locked_at` is
older than the stale window (currently fixed at 15 minutes) -- so a batch orphaned by a
crashed worker is reclaimed instead of stuck. Losing claimants exit cleanly.

### Never-Throw Queue Jobs

`ProcessImportBatchJob` / `ProcessExportBatchJob` run with `getMaxAttempts() = 1` and
`shouldRetry() = false`, and `handle()` never lets an exception escape. An adapter
exception inside a claimed batch marks the batch failed, records an `adapter_exception`
row error, dispatches `ImportExportBatchFailed`, rolls the job up, and returns cleanly.
Queue auto-redelivery is deliberately not the retry policy, because re-delivering a
half-applied batch to a non-idempotent adapter would duplicate records.

### Roll-Up and Completion

After each batch finishes, the engine sums batch counters into the job. When no batch is
left `pending`/`running`, the job transitions to `completed` (no failed records) or
`failed`, dispatching `ImportExportJobCompleted` / `ImportExportJobFailed`.

### Cancellation

Cancel transitions the job to `cancelled` and dispatches `ImportExportJobCancelled`.
Cancellation is observed at batch boundaries: queued batches check job status before
claiming and exit; a batch already in flight finishes its current run.

### Retry

`POST /jobs/{uuid}/retry`, `import-export:retry`, or `RetryService::retry()` resets each
failed batch (`pending`, locks and timestamps cleared) and re-queues it, then moves the
job back to `queued`. Retry is refused unless the adapter implements
`RetryableAdapterInterface` and reports `retryable() === true`.

## HTTP API

Routes are mounted under `/import-export` when `routes_enabled` is true. All routes
require `auth` plus the listed permission (fail-closed).

| Method | Path | Permission | Description |
| --- | --- | --- | --- |
| GET | `/import-export/adapters` | `import_export.view` | List registered importer/exporter adapters. |
| POST | `/import-export/imports` | `import_export.run_import` | Create + queue an import job (`adapter`, relative `path` required; `disk`, `mime_type`, `metadata`, `mode`, `batch_size`, `options`). |
| POST | `/import-export/exports` | `import_export.run_export` | Create + queue an export job (`adapter` required; `format`, `batch_size`, `filters`, `options`). |
| GET | `/import-export/jobs` | `import_export.view` | List jobs; query params `type`, `status`, `limit` (1-200, default 50). |
| GET | `/import-export/jobs/{uuid}` | `import_export.view` | One job with its batches. |
| GET | `/import-export/jobs/{uuid}/errors` | `import_export.view` | Stored row errors for a job. |
| GET | `/import-export/jobs/{uuid}/report` | `import_export.view` | Latest report (built on demand if absent). |
| POST | `/import-export/jobs/{uuid}/cancel` | `import_export.cancel` | Cancel a job (422 on invalid transition). |
| POST | `/import-export/jobs/{uuid}/retry` | `import_export.retry` | Re-queue failed batches of a retryable job. |
| POST | `/import-export/jobs/{uuid}/failed-records/export` | `import_export.export_failed_records` | Write failed-record errors to a managed private file (`format=ndjson|csv`). |

## CLI

| Command | Description |
| --- | --- |
| `import:run --adapter= --path= [--disk=uploads] [--mime-type=] [--mode=dry_run] [--batch-size=500] [--actor=] [--options=JSON]` | Create and queue an import job. |
| `export:run --adapter= [--format=ndjson] [--batch-size=500] [--actor=] [--filters=JSON] [--options=JSON]` | Create and queue an export job. |
| `import:list [--status=] [--limit=50]` | List import jobs. |
| `export:list [--status=] [--limit=50]` | List export jobs. |
| `import-export:status <job-uuid>` | Show job status and batches. |
| `import-export:retry <job-uuid>` | Retry failed batches (retryable adapters only). |
| `import-export:cancel <job-uuid>` | Cancel a job. |
| `import-export:cleanup [--days=30]` | Delete tmp-role files for terminal jobs older than the cutoff. |

### Service API

Use `ImportExportService` directly when another service owns the workflow:

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

## Permissions

The HTTP API is guarded by the extension-owned `import_export_permission` route
middleware, which resolves the framework `PermissionManager` and calls `can()` with the
`import_export` resource. The guard fails closed: no authenticated user, no available
permission manager, or a denial all return HTTP 403.

Permission slugs (registered in the framework permission catalog):

- `import_export.view`
- `import_export.run_import`
- `import_export.run_export`
- `import_export.cancel`
- `import_export.retry`
- `import_export.export_failed_records`

## Events

All events extend the framework `BaseEvent`. Payload fields in parentheses.

| Event | Dispatched when |
| --- | --- |
| `ImportExportJobCreated` (jobUuid, type, adapter) | A job and its batches are queued. |
| `ImportExportJobStarted` (jobUuid, type, adapter) | The first batch claim moves the job to running. |
| `ImportExportBatchCompleted` (jobUuid, batchUuid, type, adapter) | A batch finishes with zero failed records. |
| `ImportExportBatchFailed` (jobUuid, batchUuid, type, adapter, reason) | A batch finishes with failed records, or an adapter exception fails a claimed batch. |
| `ImportExportJobCompleted` (jobUuid, type, adapter) | All batches finished with no failures. |
| `ImportExportJobFailed` (jobUuid, type, adapter, reason) | All batches finished and at least one failed. |
| `ImportExportJobCancelled` (jobUuid, type, adapter) | A job is cancelled via HTTP or CLI. |

## Reports, Failed Records, and Retention

- **Reports:** `GET /jobs/{uuid}/report` returns the latest stored report or builds one
  from job state: type, adapter, status, total/processed/failed records,
  `error_overflow_count`, and the stored error count.
- **Error caps:** stored row errors are capped per severity (first N stored, currently
  1000); past the cap the engine increments the job's `error_overflow_count` instead of
  inserting rows.
- **Failed-record export:** `FailedRecordExporter` writes a job's stored row errors to a
  CSV or NDJSON file. HTTP exports always write under the managed private
  `import_export.private_path` root and return a managed relative path; CLI exports
  accept an operator-supplied path.
- **Retention:** `RetentionCleaner` (via `import-export:cleanup`) deletes files recorded
  with the `tmp` role for terminal (completed/failed/cancelled) jobs older than the
  cutoff, treating stored paths as local filesystem paths. Source and result files are
  never deleted, and job/batch/error/report rows are not pruned.

## Configuration

Configuration is loaded from `config/import_export.php` and merged under the
`import_export` key.

Several keys are **reserved**: they are declared (and their defaults match today's
hardcoded runtime values) but are not yet read by the runtime paths, so changing them
currently has no effect.

| Key | Default | Status | Purpose |
| --- | --- | --- | --- |
| `enabled` | `true` | Reserved | Extension-level enable flag (not currently consulted). |
| `routes_enabled` | `true` | Wired | Set to `false` for service/CLI-only installs. |
| `queue` | `import-export` | Wired | Queue name used for batch jobs. |
| `source_disk` | `uploads` | Wired | HTTP/CLI default source disk. |
| `source_roots` | `[]` | Wired | Optional disk-to-local-root map for import sources; otherwise each disk resolves under `<base>/<disk>`. |
| `result_disk` | `uploads` | Reserved | Result file rows currently record the job row's disk (effectively `local`). |
| `private_path` | `null` | Wired | Private local root for HTTP-managed failed-record exports; defaults to `<base>/import-export`. |
| `tmp_disk` / `tmp_path` | `local` / `import-export/tmp` | Reserved | Retention treats stored tmp paths as local filesystem paths. |
| `batch_size` | `500` | Reserved | Creation paths default to 500; override per job via `batch_size` / `--batch-size`. |
| `max_file_size` | `52428800` | Wired | Import source size limit enforced from the resolved local file size; request metadata is ignored. |
| `retention_days` | `30` | Reserved | `import-export:cleanup --days` defaults to 30 independent of config. |
| `error_cap_per_severity` | `1000` | Reserved | Runtime cap is currently fixed at 1000 per severity. |
| `stale_lock_minutes` | `15` | Reserved | Stale-lock reclaim window is currently fixed at 15 minutes. |

## Security

### Import Source Paths

HTTP and CLI import creation accepts a relative source path, never an absolute local
path or stream wrapper. The service resolves the path under the configured disk root
(`import_export.source_roots[disk]`, falling back to `<base>/<disk>`), rejects traversal,
requires the resolved file to exist and be readable, and enforces `max_file_size` from
the filesystem. Caller-supplied `metadata.size_bytes` is ignored for size enforcement.

### Archive Safety (ZIP-Slip)

ZIP bundle extraction routes every entry name through `PathGuard`, which rejects:

- absolute paths and backslash/UNC-style paths,
- parent-directory traversal that escapes the extraction root,
- empty or dot-only paths,
- Windows drive-letter paths.

After normalization, a `realpath` containment check verifies the resolved target
directory is still under the extraction root. Hostile archives are covered by tests.

### Permission Gating

Every HTTP route runs the fail-closed permission middleware described above; there is no
unauthenticated or ungated route in this extension.

### Adapter Trust Boundary

Adapters run inside the application process. They should validate source structure,
enforce domain permissions before writing records, and avoid shelling out to
user-controlled paths. The engine records errors and progress, but it does not validate
domain-specific fields, content models, prices, users, or publishing rules.

### Error Data

Stored row errors may contain excerpts or identifiers from imported data. Adapters
should avoid putting secrets, access tokens, or full sensitive records into error
contexts.

## Requirements

- PHP 8.3 or higher
- Glueful 1.55.0 or higher
- A configured queue worker for asynchronous batch processing

## License

MIT -- licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
