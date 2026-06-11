# Changelog

All notable changes to `glueful/import-export` will be documented in this file.

## [Unreleased]

### Fixed

- Return denied import/export permission checks through the framework
  `Response` error envelope instead of a manual JSON response.

## [0.1.0] - 2026-06-11

### Added

- Import/export job, batch, file, error, and report schema.
- Importer and exporter adapter contracts with tagged registry wiring.
- Streaming CSV, JSON, NDJSON, and ZIP bundle helpers with archive path guards.
- Queue-backed import and export batch jobs with engine-owned retry behavior.
- Explicit retry service, HTTP route, and CLI command.
- HTTP API for adapters, imports, exports, jobs, errors, reports, cancel, and retry.
- CLI commands for import/export list and run, status, retry, cancel, and cleanup.
- Report builder, failed-record exporter, and retention cleaner.
- Complete fake adapter flow tests for imports and exports.
- Standard extension README with inline usage, security, and adapter documentation.
