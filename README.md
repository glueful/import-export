# Import Export

Import/export engine for Glueful apps. It provides the infrastructure for CSV, JSON, NDJSON, and ZIP bundle jobs while domain adapters decide how records map to your application.

## What It Provides

- Importer and exporter registries built from service tags.
- Job, batch, file, error, and report tables.
- Queue-backed batch execution.
- Explicit retry semantics controlled by the engine.
- HTTP and CLI management APIs.
- Streaming CSV, JSON, NDJSON, and ZIP bundle helpers.
- Failed-record reporting and retention cleanup.

## Adapter Boundary

This package does not know your domain model. A CMS, commerce app, or back office system should implement its own adapters for WordPress, Markdown, products, customers, orders, or any other domain records.

Adapters are registered with:

- `import_export.importer`
- `import_export.exporter`

See [docs/adapters.md](docs/adapters.md).

## Commands

- `import:list`
- `import:run`
- `export:list`
- `export:run`
- `import-export:status`
- `import-export:retry`
- `import-export:cancel`
- `import-export:cleanup`

## Docs

- [Usage](docs/usage.md)
- [Security](docs/security.md)
- [Adapters](docs/adapters.md)
