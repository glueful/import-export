# Import/Export Adapter Guide

Import/export adapters are domain-owned integrations that plug into this package's engine. The engine owns orchestration; adapters own domain translation.

## Engine Responsibilities

- Register importers and exporters through the `import_export.importer` and `import_export.exporter` service tags.
- Create jobs, batches, file records, errors, and reports.
- Queue batch jobs.
- Claim batches atomically before processing.
- Prevent queue auto-retry from becoming the retry policy.
- Record adapter-reported errors with the configured per-severity cap.
- Roll batch progress up to the parent job.
- Stop processing cancelled jobs.
- Expose HTTP and CLI management APIs.

## Adapter Responsibilities

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

## Retry And Idempotency

Retry is explicit and engine-owned. Queue jobs catch failures and return without throwing, so the queue worker does not redeliver non-idempotent batches automatically.

Adapters that support explicit retry must implement `RetryableAdapterInterface` and return `true` from `retryable()`.

Retryable adapters should make `process()` idempotent per batch, or safely detect already-applied records. Non-idempotent adapters should not implement the retry capability.

## Service Tags

Adapter packages should expose adapter services and tag them for collection by the extension provider:

```php
return [
    App\Imports\WordPressImporter::class => [
        'class' => App\Imports\WordPressImporter::class,
        'shared' => true,
        'autowire' => true,
        'tags' => ['import_export.importer'],
    ],
];
```

Use `import_export.exporter` for exporters.
