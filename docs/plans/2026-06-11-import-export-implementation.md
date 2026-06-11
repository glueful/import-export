# Import Export Extension Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `glueful/import-export` as the reusable import/export engine for Glueful apps: adapter discovery, files, batching, queues, validation reports, progress, dry runs, retries, failed-record exports, and cleanup.

**Architecture:** Engine and domain adapters are separate. This extension owns the job engine, file readers/writers, job/batch/error/report tables, queue jobs, progress APIs, and tagged adapter registries. Lemma, commerce, users, or app packages own adapters that map source records into their own domain models. No broad framework-core seam is required in v1.

**Tech Stack:** PHP 8.3+, Glueful Framework 1.54.0+, PHPUnit 10.5, PHPStan level 6, Glueful extension `ServiceProvider`, Glueful queue jobs, Glueful storage, Glueful locks/events, Glueful container tags, SQLite/temp database for repository tests.

**Spec:** `docs/specs/2026-06-11-import-export-design.md` (read it first).

**Conventions used throughout:**
- Namespace `Glueful\Extensions\ImportExport\` maps to `src/`; tests namespace `Glueful\Extensions\ImportExport\Tests\` maps to `tests/`.
- Run commands from `/Users/michaeltawiahsowah/Sites/glueful/extensions/import-export`.
- Use service tags `import_export.importer` and `import_export.exporter`.
- Keep WordPress, Markdown/MDX, CSV-to-Lemma, commerce, and users mappings out of this package; those are domain adapters.
- Treat uploaded archives as hostile input; ZIP-slip protection is mandatory.
- Every implementation task is red/green: write the named failing test, run the exact `--filter`, implement the smallest passing code, rerun the same filter, then commit.
- Put controllers, guards, managers, repositories, commands, queue jobs, and registries in `ImportExportServiceProvider::services()`. The container compiles before `boot()`, so `boot()` is only for loading routes, migrations, and commands.
- Retry is engine-owned. Queue jobs must catch adapter/runtime failures, mark the batch/job failed, and return without throwing; Glueful queue auto-retry must not redeliver non-idempotent adapters.
- Batch claim updates must always set a fresh `locked_at` value so changed-row detection works on MySQL and stale-lock reclaims are observable.
- Empty adapter-tag registries are valid on fresh installs; guard `$container->has('import_export.importer')` / `$container->has('import_export.exporter')` when collecting tagged services.
- Permission guard tests must cover the four failure/success cases named in Task 7.

---

## File Structure

- `composer.json`: package metadata, one PSR-4 root, `autoload.classmap: ["migrations/"]`, `extra.glueful`, scripts.
- `phpunit.xml`: Unit and Integration suites.
- `phpstan.neon`: level 6.
- `CHANGELOG.md`: `0.1.0` entry.
- `config/import_export.php`: disks, queue, temp path, batch size, retention, error cap, stale lock timeout.
- `migrations/001_CreateImportExportTables.php`: jobs/batches/files/errors/reports.
- `src/Contracts/*`: importer/exporter interfaces.
- `src/Registry/*`: tagged importer/exporter registries.
- `src/Support/*`: DTOs, path guard, job/batch statuses.
- `src/Files/*`: CSV/JSON/NDJSON/ZIP readers and writers.
- `src/Repositories/*`: jobs/batches/files/errors/reports.
- `src/Services/*`: planner, engine, batch runner, retry, report, retention.
- `src/Jobs/*`: never-throw queue jobs.
- `src/Events/*`: job/batch lifecycle events.
- `src/Http/*`: permission guard and controllers.
- `src/Console/*`: import/export/status/retry/cleanup commands.
- `tests/Support/ImportExportTestCase.php`: in-memory SQLite harness, migrations, tiny container.

---

### Task 1: Package Scaffold, Tooling, And Test Harness

**Files:**
- Create or update: `composer.json`
- Create: `phpunit.xml`
- Create: `phpstan.neon`
- Create: `tests/bootstrap.php`
- Create: `tests/Support/ImportExportTestCase.php`
- Create: `CHANGELOG.md`
- Review existing: `.gitignore`

- [ ] Create `composer.json` with package name `glueful/import-export`, type `glueful-extension`, require php only, require-dev `glueful/framework:^1.54.0`, `phpunit/phpunit:^10.5`, `squizlabs/php_codesniffer:^3.6`, `phpstan/phpstan:^1.0`, PSR-4 autoload for `Glueful\Extensions\ImportExport\`, `autoload.classmap: ["migrations/"]`, and `extra.glueful` provider `Glueful\Extensions\ImportExport\ImportExportServiceProvider`, version `0.1.0`, requires `{"glueful": ">=1.54.0", "extensions": []}`.
- [ ] Create `phpunit.xml` with Unit (`tests/Unit`) and Integration (`tests/Integration`) suites and `tests/bootstrap.php` as bootstrap.
- [ ] Create `phpstan.neon` with `level: 6`, `paths: [src]`, and bootstrap through Composer autoload if needed.
- [ ] Create `tests/bootstrap.php` requiring `vendor/autoload.php`.
- [ ] Create `tests/Support/ImportExportTestCase.php` modeled on `glueful/subscriptions`' SQLite harness: create a `Glueful\Database\Connection` against in-memory SQLite, run `CreateImportExportTables` once it exists, expose `connection()` and `appContext()`, and provide helpers `seedJob()` and `seedBatch()`.
- [ ] Preserve the existing `.gitignore` if present; only add missing ignores for `/vendor/`, `/composer.lock`, `.phpunit.cache/`, and `.DS_Store`.
- [ ] Create `CHANGELOG.md` with an Unreleased section and an initial `0.1.0` planning entry.
- [ ] Run `composer install`.
- [ ] Run `vendor/bin/phpunit --filter=ImportExportTestCase`.
- [ ] Expected: FAIL until migration class exists; keep this known failure for Task 3.
- [ ] Commit: `git add composer.json phpunit.xml phpstan.neon tests/bootstrap.php tests/Support/ImportExportTestCase.php CHANGELOG.md .gitignore && git commit -m "chore: scaffold import-export extension tooling"`

---

### Task 2: Adapter Contracts, DTOs, And Registries

**Files:**
- Create: `src/Contracts/ImporterInterface.php`
- Create: `src/Contracts/ExporterInterface.php`
- Create: `src/Registry/ImporterRegistry.php`
- Create: `src/Registry/ExporterRegistry.php`
- Create: `src/Support/ImportSource.php`
- Create: `src/Support/ImportOptions.php`
- Create: `src/Support/ImportPlan.php`
- Create: `src/Support/ImportBatch.php`
- Create: `src/Support/ImportContext.php`
- Create: `src/Support/ImportBatchResult.php`
- Create: `src/Support/ExportOptions.php`
- Create: `src/Support/ExportPlan.php`
- Create: `src/Support/ExportBatch.php`
- Create: `src/Support/ExportContext.php`
- Create: `src/Support/ExportBatchResult.php`
- Test: `tests/Registry/ImporterRegistryTest.php`
- Test: `tests/Registry/ExporterRegistryTest.php`

- [ ] Write failing registry tests for lookup by key, duplicate key rejection, useful not-found exception, and empty tagged registry returning an empty list without throwing.
- [ ] Run `vendor/bin/phpunit --filter='ImporterRegistryTest|ExporterRegistryTest'`.
- [ ] Expected: FAIL because contracts/registries are missing.
- [ ] Define `ImporterInterface` and `ExporterInterface` exactly around key/label/supports/plan/process responsibilities from the spec.
- [ ] Keep DTOs immutable where practical and explicit about job UUID, batch UUID, disk/path, adapter key, mode, options, and actor.
- [ ] Implement importer/exporter registries that receive tagged services and resolve by adapter key.
- [ ] Duplicate adapter keys should fail loudly during registry construction.
- [ ] `supports()` is a capability check; it must not mutate files or database state.
- [ ] Adapter DTOs must include an explicit retry/idempotency capability on plans or adapter metadata so the retry service can refuse non-idempotent adapters.
- [ ] Unit test adapter lookup by key.
- [ ] Unit test duplicate keys throw a clear exception.
- [ ] Unit test no matching adapter produces a useful not-found exception.
- [ ] Run `vendor/bin/phpunit --filter='ImporterRegistryTest|ExporterRegistryTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Contracts src/Registry src/Support tests/Registry && git commit -m "feat(import-export): add adapter contracts and registries"`

---

### Task 3: Database Migrations And Repositories

**Files:**
- Create: `migrations/001_CreateImportExportTables.php`
- Create: `src/Repositories/ImportExportJobRepository.php`
- Create: `src/Repositories/ImportExportBatchRepository.php`
- Create: `src/Repositories/ImportExportFileRepository.php`
- Create: `src/Repositories/ImportExportErrorRepository.php`
- Create: `src/Repositories/ImportExportReportRepository.php`
- Test: `tests/Repositories/ImportExportJobRepositoryTest.php`
- Test: `tests/Repositories/ImportExportBatchRepositoryTest.php`
- Test: `tests/Repositories/ImportExportErrorRepositoryTest.php`

- [ ] Write failing `tests/Integration/MigrationsTest.php::testImportExportTablesExist` using `ImportExportTestCase`.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest`.
- [ ] Expected: FAIL because `CreateImportExportTables` is missing.
- [ ] Add migrations for `import_export_jobs`, `import_export_batches`, `import_export_files`, `import_export_errors`, and `import_export_reports` exactly as defined in the spec.
- [ ] Use `uuid` string columns for code-level references; do not add database foreign keys to user or tenant tables.
- [ ] Update `ImportExportTestCase` to run `CreateImportExportTables`.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest`.
- [ ] Expected: PASS.
- [ ] Write failing repository tests for lifecycle transitions, conditional batch claim, stale lock reclaim, and error cap overflow.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportJobRepositoryTest|ImportExportBatchRepositoryTest|ImportExportErrorRepositoryTest'`.
- [ ] Expected: FAIL because repositories are missing.
- [ ] Implement job lifecycle transitions: `pending`, `planning`, `queued`, `running`, `completed`, `failed`, `cancelled`.
- [ ] Implement batch lifecycle transitions with attempts, lock owner, lock timestamp, and stale-lock reclaim support.
- [ ] Implement a conditional batch claim update that only claims an eligible batch and returns whether the claim succeeded. The update must set `status`, increment `attempts`, and always set a new non-null `locked_at` timestamp so changed-row detection is reliable.
- [ ] Implement error recording with per-job/per-severity cap defaulting to 1000 and overflow count stored on job/report.
- [ ] Test lifecycle transitions and invalid transition rejection.
- [ ] Test conditional batch claim prevents double-claiming.
- [ ] Test stale reclaim changes `locked_at` even when `status` was already `running`.
- [ ] Test error cap records overflow count instead of unbounded rows.
- [ ] Run `vendor/bin/phpunit --filter='MigrationsTest|ImportExportJobRepositoryTest|ImportExportBatchRepositoryTest|ImportExportErrorRepositoryTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add migrations src/Repositories tests/Integration tests/Repositories tests/Support && git commit -m "feat(import-export): add schema and repositories"`

---

### Task 4: File Readers, Writers, And Archive Safety

**Files:**
- Create: `src/Files/CsvReader.php`
- Create: `src/Files/CsvWriter.php`
- Create: `src/Files/JsonReader.php`
- Create: `src/Files/NdjsonReader.php`
- Create: `src/Files/NdjsonWriter.php`
- Create: `src/Files/ZipBundleReader.php`
- Create: `src/Files/ZipBundleWriter.php`
- Create: `src/Support/PathGuard.php`
- Test: `tests/Files/CsvReaderTest.php`
- Test: `tests/Files/NdjsonReaderTest.php`
- Test: `tests/Files/ZipBundleReaderTest.php`
- Test: `tests/Support/PathGuardTest.php`

- [ ] Write failing reader/writer tests for CSV streaming, malformed NDJSON reporting, JSON object/array parsing, ZIP bundle creation, and ZIP-slip rejection.
- [ ] Run `vendor/bin/phpunit --filter='CsvReaderTest|NdjsonReaderTest|ZipBundleReaderTest|PathGuardTest'`.
- [ ] Expected: FAIL because file helpers are missing.
- [ ] Implement CSV, JSON, and NDJSON readers with streaming/iterator-style APIs where possible.
- [ ] Implement CSV and NDJSON writers for exports and failed-record output.
- [ ] Implement ZIP bundle read/write helpers for archive import/export.
- [ ] Add `PathGuard` normalization that rejects absolute paths, `..` traversal, drive-letter paths, and paths that escape the extraction root.
- [ ] Ensure temporary extraction directories are unique per job and cleaned after completion/failure/cancel.
- [ ] Unit test CSV header parsing and row iteration.
- [ ] Unit test NDJSON malformed-line reporting.
- [ ] Unit test ZIP-slip attempts are rejected before extraction.
- [ ] Run `vendor/bin/phpunit --filter='CsvReaderTest|NdjsonReaderTest|ZipBundleReaderTest|PathGuardTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Files src/Support tests/Files tests/Support && git commit -m "feat(import-export): add streaming file readers and archive guards"`

---

### Task 5: Engine Services And Queue Jobs

**Files:**
- Create: `src/Services/ImportExportService.php`
- Create: `src/Services/ImportPlanner.php`
- Create: `src/Services/ExportPlanner.php`
- Create: `src/Services/BatchRunner.php`
- Create: `src/Jobs/ProcessImportBatchJob.php`
- Create: `src/Jobs/ProcessExportBatchJob.php`
- Test: `tests/Services/ImportExportServiceTest.php`
- Test: `tests/Services/BatchRunnerTest.php`
- Test: `tests/Jobs/ProcessImportBatchJobTest.php`
- Test: `tests/Jobs/ProcessExportBatchJobTest.php`

- [ ] Write failing service/job tests for dry-run no-commit, commit progress updates, cancellation at batch boundary, never-throw queue job failure handling, and non-idempotent adapter failure not being auto-retried.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportServiceTest|BatchRunnerTest|ProcessImportBatchJobTest|ProcessExportBatchJobTest'`.
- [ ] Expected: FAIL because services/jobs are missing.
- [ ] Implement import job creation: validate source, resolve importer, store file metadata, create job row, ask adapter for plan, create batch rows, enqueue batch jobs.
- [ ] Implement export job creation: resolve exporter, ask adapter for plan, create job/batches, enqueue batch jobs, and write result metadata.
- [ ] Use `QueueManager::push()` for individual jobs and `QueueManager::bulk()` where batch enqueueing benefits from it.
- [ ] Queue jobs should extend `Glueful\Queue\Job` or implement `Glueful\Queue\Contracts\JobInterface`.
- [ ] Implement queue job `handle()` methods as never-throw: catch adapter/runtime exceptions, record batch/job failure through repositories, dispatch failure events, and return normally so queue worker auto-retry does not own import/export retry semantics.
- [ ] Batch runner must claim a batch before processing, release/update it after processing, and skip if cancelled.
- [ ] Cancellation is checked at batch boundaries.
- [ ] Retries are only allowed for adapters that declare idempotent behavior; non-idempotent adapters are non-retryable.
- [ ] Test dry-run jobs do not call adapter commit/write paths.
- [ ] Test cancelled jobs stop before processing the next batch.
- [ ] Test non-idempotent adapter failures are marked failed without retry scheduling.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportServiceTest|BatchRunnerTest|ProcessImportBatchJobTest|ProcessExportBatchJobTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Services src/Jobs tests/Services tests/Jobs && git commit -m "feat(import-export): run batches with engine-owned retry semantics"`

---

### Task 6: Explicit Retry API, CLI, And Service

**Files:**
- Create: `src/Services/RetryService.php`
- Create: `src/Http/Controllers/ImportExportRetryController.php`
- Create: `src/Console/ImportExportRetryCommand.php`
- Test: `tests/Services/RetryServiceTest.php`
- Test: `tests/Http/ImportExportRetryControllerTest.php`
- Test: `tests/Console/ImportExportRetryCommandTest.php`

- [ ] Write failing `RetryServiceTest` proving retry refuses non-idempotent adapters, requeues failed batches for idempotent adapters, and records a retry attempt.
- [ ] Write failing route and command tests for `POST /import-export/jobs/{uuid}/retry` and `import-export:retry <job>`.
- [ ] Run `vendor/bin/phpunit --filter='RetryServiceTest|ImportExportRetryControllerTest|ImportExportRetryCommandTest'`.
- [ ] Expected: FAIL because retry service/route/command are missing.
- [ ] Implement `RetryService` as the only retry entrypoint. It must check adapter idempotency before requeueing failed batches.
- [ ] Add retry route protected by `import_export.retry`.
- [ ] Add retry CLI command that returns a non-zero exit code when retry is refused.
- [ ] Run `vendor/bin/phpunit --filter='RetryServiceTest|ImportExportRetryControllerTest|ImportExportRetryCommandTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Services src/Http/Controllers src/Console tests && git commit -m "feat(import-export): add explicit retry service route and command"`

---

### Task 7: Reports, Failed-Record Export, Retention, And Cleanup

**Files:**
- Create: `src/Services/ReportBuilder.php`
- Create: `src/Services/FailedRecordExporter.php`
- Create: `src/Services/RetentionCleaner.php`
- Create: `src/Console/ImportExportCleanCommand.php`
- Test: `tests/Services/ReportBuilderTest.php`
- Test: `tests/Services/FailedRecordExporterTest.php`
- Test: `tests/Services/RetentionCleanerTest.php`

- [ ] Write failing report/failed-record/retention tests for totals, overflow counts, CSV/NDJSON failed-record export, and temp cleanup on completed/failed/cancelled states.
- [ ] Run `vendor/bin/phpunit --filter='ReportBuilderTest|FailedRecordExporterTest|RetentionCleanerTest'`.
- [ ] Expected: FAIL because services are missing.
- [ ] Build final reports from job, batch, file, and error repositories.
- [ ] Include totals for total/processed/failed records and error overflow count.
- [ ] Implement failed-record export as CSV or NDJSON in v1.
- [ ] Store failed-record export paths on report/file rows.
- [ ] Implement default retention of 30 days for completed/failed/cancelled jobs and temp files.
- [ ] Ensure cleanup never deletes active source/result paths for running jobs.
- [ ] Test failed-record export includes capped errors and references overflow count.
- [ ] Test retention cleanup removes old temp files and keeps active jobs.
- [ ] Run `vendor/bin/phpunit --filter='ReportBuilderTest|FailedRecordExporterTest|RetentionCleanerTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Services src/Console tests/Services && git commit -m "feat(import-export): build reports and retention cleanup"`

---

### Task 8: Events

**Files:**
- Create: `src/Events/ImportExportJobCreated.php`
- Create: `src/Events/ImportExportJobStarted.php`
- Create: `src/Events/ImportExportBatchCompleted.php`
- Create: `src/Events/ImportExportBatchFailed.php`
- Create: `src/Events/ImportExportJobCompleted.php`
- Create: `src/Events/ImportExportJobFailed.php`
- Create: `src/Events/ImportExportJobCancelled.php`
- Test: `tests/Events/ImportExportEventsTest.php`
- Test: `tests/Services/ImportExportEventDispatchTest.php`

- [ ] Write failing event tests asserting every event extends `Glueful\Events\Contracts\BaseEvent` and exposes job UUID, type, adapter, and batch UUID where applicable.
- [ ] Write failing dispatch tests with a fake `EventService` proving job created/started/completed/failed/cancelled and batch completed/failed events fire from the engine.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportEventsTest|ImportExportEventDispatchTest'`.
- [ ] Expected: FAIL because events are missing.
- [ ] Implement the seven event classes and dispatch from import/export services, batch runner, retry/cancel flows, and queue-job failure handling.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportEventsTest|ImportExportEventDispatchTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add src/Events src/Services src/Jobs tests/Events tests/Services && git commit -m "feat(import-export): emit job and batch lifecycle events"`

---

### Task 9: Service Provider, Tags, Config, Permissions, And Guards

**Files:**
- Create: `src/ImportExportServiceProvider.php`
- Create: `config/import_export.php`
- Create: `src/Http/RequireImportExportPermission.php`
- Test: `tests/ImportExportServiceProviderTest.php`
- Test: `tests/Http/RequireImportExportPermissionTest.php`

- [ ] Write failing `ImportExportServiceProviderTest` asserting services include aliases/bindings for registries, engine services, queue jobs, controllers, commands, and guard before `boot()` runs; also assert empty importer/exporter tag services do not throw.
- [ ] Write failing guard tests named `testPermissionMiddlewareReturns403WithoutAuthenticatedUser`, `testPermissionMiddlewareReturns403WhenManagerUnavailable`, `testPermissionMiddlewareReturns403WithRealManagerAndNoProvider`, `testPermissionMiddlewareReturns403WhenPermissionDenied`, and `testPermissionMiddlewareCallsNextOnlyWhenAllowed`.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportServiceProviderTest|RequireImportExportPermissionTest'`.
- [ ] Expected: FAIL because provider/guard wiring is missing.
- [ ] Implement `ImportExportServiceProvider extends Glueful\Extensions\ServiceProvider`.
- [ ] Register migrations with source `glueful/import-export`.
- [ ] Register importer/exporter registries using tagged services `import_export.importer` and `import_export.exporter`.
- [ ] Support both structured tags with priority and plain string tags.
- [ ] Use container references/aliases for tagged arguments, not DSL closure factories that production compilation rejects.
- [ ] Guard absent tag services with `$container->has()` so fresh installs with no adapters still boot.
- [ ] Register config for default disk, temp path, batch size, queue name, retention days, error cap, and route enablement.
- [ ] Declare permissions for import read/write/cancel, export read/write/cancel, report read, and cleanup.
- [ ] Implement an extension-owned guard/middleware that calls `Glueful\Permissions\PermissionManager::can()` directly for management routes.
- [ ] Unit test tagged adapter collection order and duplicate detection.
- [ ] Unit test permission denial blocks management endpoints.
- [ ] Run `vendor/bin/phpunit --filter='ImportExportServiceProviderTest|RequireImportExportPermissionTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add config src/ImportExportServiceProvider.php src/Http tests && git commit -m "feat(import-export): wire provider tags and permission guard"`

---

### Task 10: HTTP API And CLI

**Files:**
- Create: `routes/routes.php`
- Create: `src/Http/Controllers/ImportJobController.php`
- Create: `src/Http/Controllers/ExportJobController.php`
- Create: `src/Http/Controllers/ImportExportReportController.php`
- Create: `src/Console/ImportCreateCommand.php`
- Create: `src/Console/ExportCreateCommand.php`
- Create: `src/Console/ImportExportStatusCommand.php`
- Create: `src/Console/ImportExportCancelCommand.php`
- Test: `tests/Http/ImportJobControllerTest.php`
- Test: `tests/Http/ExportJobControllerTest.php`
- Test: `tests/Http/ImportExportAdapterControllerTest.php`
- Test: `tests/Console/ImportExportCommandsTest.php`

- [ ] Write failing controller tests for all locked routes: `GET /import-export/adapters`, `POST /import-export/imports`, `POST /import-export/exports`, `GET /import-export/jobs`, `GET /import-export/jobs/{uuid}`, `GET /import-export/jobs/{uuid}/errors`, `GET /import-export/jobs/{uuid}/report`, `POST /import-export/jobs/{uuid}/cancel`, and retry from Task 6.
- [ ] Write failing command tests for `import:list`, `import:run`, `export:list`, `export:run`, `import-export:status`, `import-export:retry`, and cleanup.
- [ ] Run `vendor/bin/phpunit --filter='ImportJobControllerTest|ExportJobControllerTest|ImportExportAdapterControllerTest|ImportExportCommandsTest'`.
- [ ] Expected: FAIL because routes/controllers/commands are missing.
- [ ] Add routes for creating import jobs, creating export jobs, reading job status, listing batches/errors, downloading reports, and cancelling jobs.
- [ ] Add adapter listing route.
- [ ] Keep routes optional/config-gated for applications that only use service/CLI APIs.
- [ ] Add CLI commands for list/import run/export list/export run/status/retry/cancel/cleanup.
- [ ] API create endpoints should accept adapter key, mode, file/source reference, and options.
- [ ] Response payloads should expose job UUID, status, progress totals, and report/result links when ready.
- [ ] Test API job creation enqueues queue jobs.
- [ ] Test status endpoint reports progress from job/batch repositories.
- [ ] Test cancellation changes job state and stops later batch processing.
- [ ] Run `vendor/bin/phpunit --filter='ImportJobControllerTest|ExportJobControllerTest|ImportExportAdapterControllerTest|ImportExportCommandsTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add routes src/Http/Controllers src/Console tests && git commit -m "feat(import-export): add public API and CLI"`

---

### Task 11: Fake Adapter Test Suite And Domain Adapter Contract Documentation

**Files:**
- Create: `tests/Fakes/FakeImporter.php`
- Create: `tests/Fakes/FakeExporter.php`
- Create: `docs/adapters.md`
- Create: `docs/lemma-adapter-notes.md`

- [ ] Add fake importer/exporter implementations used by integration-style tests.
- [ ] Test a complete import flow with fake adapter: create job, plan batches, process batches, record report.
- [ ] Test a complete export flow with fake adapter: create job, write result, record report.
- [ ] Document adapter responsibilities versus engine responsibilities.
- [ ] Document how Lemma should implement WordPress, Markdown/MDX, and CSV-to-entry adapters outside this package.
- [ ] Document retry/idempotency expectations for adapters.
- [ ] Run `vendor/bin/phpunit --filter='CompleteImportFlowTest|CompleteExportFlowTest'`.
- [ ] Expected: PASS.
- [ ] Commit: `git add tests/Fakes tests/Integration docs/adapters.md docs/lemma-adapter-notes.md && git commit -m "test(import-export): cover complete fake adapter flows"`

---

### Task 12: Documentation And Verification

**Files:**
- Update: `README.md`
- Create: `docs/usage.md`
- Create: `docs/security.md`
- Update: `CHANGELOG.md`

- [ ] Document engine usage, supported file formats, queue behavior, reports, retention, and failed-record exports.
- [ ] Document archive safety rules and ZIP-slip protections.
- [ ] Document service tags for domain adapters.
- [ ] Document retry as engine-owned and queue jobs as never-throw.
- [ ] Update `CHANGELOG.md` with a `0.1.0` Added section.
- [ ] Run `composer validate --strict`.
- [ ] Run `vendor/bin/phpunit`.
- [ ] Run `vendor/bin/phpstan analyse src --level=6`.
- [ ] Run `vendor/bin/phpcs --standard=PSR12 src` if phpcs is installed.
- [ ] Commit: `git add README.md docs CHANGELOG.md && git commit -m "docs(import-export): document engine adapters security and retry"`
