# Changelog

All notable changes to `glueful/import-export` will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added
- Declares the Glueful schema manifest (migration descriptors, requires.extensions, structural
  verifier); requires framework >=1.79.0 for schema-on-enable participation. Migrations are now
  registered by the manifest, not by provider boot.


## [1.1.1] - 2026-06-16

### Fixed

- Register migration paths during provider boot so `migrate:run` sees the
  import/export schema through the same CLI lifecycle used by other extension
  migrations.

## [1.1.0] - 2026-06-14

### Changed

- Migrated OpenAPI documentation to the framework 1.57.0 reflect generator. Route
  documentation (summaries, query parameters, request-body fields and response codes)
  is now expressed as typed `#[ApiOperation]`, `#[QueryParam]` and `#[ApiResponse]`
  attributes on the controller methods; the now-inert route-file docblocks were removed.
  Docs-only — no runtime behaviour changes.
- Raised the minimum framework requirement to `^1.57.0`.

## [1.0.1] - 2026-06-13

### Fixed

- Harden HTTP failed-record export so request bodies can no longer choose arbitrary filesystem paths; exports now write under a managed private root and require `import_export.export_failed_records`.
- Confine import source paths to configured disk roots, fail closed on missing/unreadable files, and enforce `max_file_size` from the resolved file instead of trusting request metadata.
- Scope HTTP job list/read/operate endpoints to the authenticated job creator by default, with a new `import_export.manage_all` permission for trusted cross-user operators.
- Add ZIP bundle extraction limits for entry count, per-entry uncompressed size, and total uncompressed size.
- Bound import/export plan fan-out with `import_export.max_batches_per_job` before creating job and batch rows.
- Change the export result default disk from `uploads` to private/local `local`.
- Prune old terminal job database rows during retention cleanup after unlinking tmp-role files.
- Escape spreadsheet formula-like CSV values and reject null bytes in guarded relative paths.
- Store generic adapter exception messages for failed batches instead of persisting raw exception text.
- Add a pre-read size limit to the JSON file reader.
- Add cascade foreign keys between import/export jobs and their batch, file, error, and report rows in the base schema.

## [1.0.0] - 2026-06-11

First release. A domain-blind **import/export engine** for Glueful: the engine owns
jobs, batches, queueing, claiming, errors, reports, and retries, while domain adapters
(registered through service tags) own what records mean. Requires
`glueful/framework >= 1.55.0` and a running queue worker.

### Added

- **Engine/adapter split:** `ImporterInterface` (`key`/`label`/`supports`/`plan`/`process`)
  and `ExporterInterface` (`key`/`label`/`plan`/`process`) contracts with immutable
  support DTOs (`ImportSource`, `ImportOptions`/`ExportOptions`, `ImportPlan`/`ExportPlan`,
  `ImportBatch`/`ExportBatch`, batch results, and process contexts). Adapters are
  collected into duplicate-key-rejecting registries via the `import_export.importer` /
  `import_export.exporter` service tags -- both the plain-string and the
  `{name, priority}` tag forms are supported.
- **Schema (5 tables):** `import_export_jobs` (status machine, mode, progress counters,
  `error_overflow_count`), `import_export_batches` (sequence/offset/limit windows,
  attempts, lock timestamps), `import_export_files` (source/result/tmp roles),
  `import_export_errors` (per-row severity/code/message/context), and
  `import_export_reports`.
- **Queue-backed processing with never-throw batch jobs:**
  `ProcessImportBatchJob`/`ProcessExportBatchJob` run with max attempts 1 and never let
  exceptions escape `handle()`. An adapter exception inside a claimed batch marks the
  batch failed, records an `adapter_exception` row error, dispatches
  `ImportExportBatchFailed`, rolls the job up, and returns cleanly -- queue
  auto-redelivery is deliberately not the retry policy.
- **Atomic batch claiming with stale-lock reclaim:** a single conditional UPDATE claims
  `pending` batches (or `running` batches whose `locked_at` is older than the
  15-minute stale window), always refreshing `locked_at` and incrementing `attempts`;
  losing claimants exit cleanly. Cancellation is observed at batch boundaries before
  claiming.
- **Job lifecycle:** validated status machine
  (`pending -> planning -> queued -> running -> completed|failed|cancelled`, plus
  `failed -> queued` via explicit retry), dry-run vs commit import modes (dry_run
  default; mode delivered to adapters via `ImportContext`), and per-batch roll-up into
  job progress and terminal status.
- **Engine-owned explicit retry:** `RetryService` resets failed batches and re-queues
  them, restricted to adapters implementing `RetryableAdapterInterface` with
  `retryable() === true`. Retry re-delivers whole batch windows, so retryable adapters
  must upsert by a stable source key.
- **Seven lifecycle events** (all extend the framework `BaseEvent`): job
  created/started/completed/failed/cancelled and batch completed/failed, dispatched
  from creation, the batch runner roll-up, and cancellation paths.
- **Row-error capture with caps:** stored errors are capped per severity (first 1000);
  past the cap the job's `error_overflow_count` is incremented instead of inserting
  rows. `FailedRecordExporter` writes stored errors to CSV/NDJSON (service-level; no
  HTTP/CLI surface yet).
- **Reports:** `ReportBuilder` summarizes type, adapter, status, totals, failed and
  overflow counts; the report endpoint returns the latest stored report or builds one
  on demand.
- **HTTP management API** under `/import-export` (adapters list, import/export create,
  job list/show/errors/report/cancel/retry) with OpenAPI route docblocks, gated by
  `auth` plus the fail-closed `import_export_permission` middleware
  (`PermissionManager::can()`; missing user/manager or denial => 403).
- **Permission catalog:** `import_export.view`, `.run_import`, `.run_export`,
  `.cancel`, `.retry` registered via the provider's permission definitions.
- **CLI:** `import:run`, `export:run`, `import:list`, `export:list`,
  `import-export:status`, `import-export:retry`, `import-export:cancel`,
  `import-export:cleanup`.
- **Streaming file helpers:** CSV, JSON, NDJSON readers/writers (generator-based row
  streaming) and ZIP bundle reader/writer.
- **Retention cleanup:** deletes `tmp`-role files for terminal jobs older than the
  cutoff (source/result files and DB rows are never pruned).
- **Config** (`config/import_export.php`) merged under `import_export`; `queue` and
  `routes_enabled` are wired, while `enabled`, `source_disk`, `result_disk`,
  `tmp_disk`/`tmp_path`, `batch_size`, `max_file_size`, `retention_days`,
  `error_cap_per_severity`, and `stale_lock_minutes` are reserved keys whose defaults
  match the current hardcoded runtime values (see README).
- **Tests:** unit + SQLite integration suites covering complete fake-adapter import and
  export flows, claiming/reclaim, never-throw failure paths, event dispatch, hostile
  ZIP archives, permission guard fail-closed behavior, and provider service loading
  through the real production services loader.

### Security

- **ZIP-slip protection:** every archive entry passes through `PathGuard`
  (normalization rejecting absolute, traversal, empty, drive-letter, and
  backslash/UNC-style paths) plus a `realpath` containment check under the extraction
  root; hostile-archive tested.
- **Fail-closed HTTP gating:** every route requires auth plus an extension permission;
  denials and missing permission infrastructure return the framework `Response` error
  envelope with HTTP 403.
