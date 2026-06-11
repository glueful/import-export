# Security

Import/export touches user-provided files, long-running jobs, and domain data. Keep the engine boundary strict and treat adapters as privileged application code.

## Archive Safety

ZIP bundle readers and writers use `PathGuard` to block unsafe paths.

Blocked paths include:

- Absolute paths.
- Parent-directory traversal.
- Empty paths.
- Paths containing NUL bytes.
- Windows drive paths.
- Windows UNC paths.

This prevents ZIP-slip style extraction into unintended locations.

## Adapter Trust Boundary

Adapters run inside the application process. They should validate source structure, enforce domain permissions before writing records, and avoid shelling out to user-controlled paths.

The engine records errors and progress, but it does not validate domain-specific fields, content models, prices, users, or publishing rules.

## Retry Safety

Retry is explicit and engine-owned. Queue jobs catch exceptions and return without throwing so queue workers do not automatically redeliver batches.

Adapters should implement `RetryableAdapterInterface` only when `process()` is idempotent for a batch or can safely detect records already applied.

## Permissions

The HTTP API uses the `import_export_permission` route middleware and the following permission slugs:

- `import_export.view`
- `import_export.run_import`
- `import_export.run_export`
- `import_export.cancel`
- `import_export.retry`

If no permission manager is available, the guard fails closed with HTTP 403.

## File Retention

Retention cleanup only deletes temporary files recorded with role `tmp` for terminal jobs older than the cutoff. It does not delete import source files or export results.

## Error Data

Stored row errors may contain excerpts or identifiers from imported data. Adapters should avoid putting secrets, access tokens, or full sensitive records into error contexts.
