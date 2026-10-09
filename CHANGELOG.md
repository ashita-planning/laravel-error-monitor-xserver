# Changelog

Changes recorded for `ashita-planning/laravel-error-monitor-xserver` from the
introduction of this file. Earlier release history has not been reconstructed.

## [Unreleased]

### Added

- Support opt-in start-date filenames through `XSERVER_LOG_FILE_DATE_BASIS=start`
  and `error-monitor:xserver-setup --file-date-basis=start`. Default `end`
  preserves existing candidate dates and coverage boundaries.
- Report the convention in status JSON and file metadata; describe required
  missing or unreadable files accurately in human-readable status output.
- Add synthetic gzip integration regressions for both kinds and conventions,
  month/year rollover, target-day filtering and rerun deduplication.

## [1.2.0] - 2026-10-03

### Added

- Add error-monitor:xserver-setup for missing account, domain and enabled settings.
- Require core ^1.2 for the new shared setup API; publish the core release before the adapter.

### Documentation

- Clarify optional adapter dependencies, Laravel 13 requirements, scheduling
  and incomplete coverage when expected files are missing.
- Link to the core scheduling and maintenance guides.
