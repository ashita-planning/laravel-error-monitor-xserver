# Laravel Error Monitor — XServer adapter

`ashita-planning/laravel-error-monitor-xserver` lets
[`ashita-planning/laravel-error-monitor`](https://github.com/ashita-planning/laravel-error-monitor)
read the Apache logs XServer stores in a hosting account's own home directory.

The core package knows nothing about XServer, and this one contains no analysis:
it resolves paths, hands readable files over through the core's
`ServerLogSource` contract, and stops there.

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, 12, or 13 (Laravel 13 requires PHP 8.3 or newer)
- `ashita-planning/laravel-error-monitor` ^1.2 (new shared setup API)

## Guided setup

After installation, see the core [setup guide](https://github.com/ashita-planning/laravel-error-monitor/blob/main/docs/setup.md) for missing-settings-only setup and Agent assistance.

## Installation

```bash
composer require ashita-planning/laravel-error-monitor-xserver
php artisan vendor:publish --provider="Apkk\LaravelErrorMonitorXserver\XserverLogSourceServiceProvider" --tag=error-monitor-xserver-config
```

Laravel discovers the provider automatically. Then set the account details:

```dotenv
ERROR_MONITOR_XSERVER_ENABLED=true
XSERVER_SERVER_ID=sv00000
XSERVER_DOMAIN=example.com
```

Verify what it can see before relying on it:

```bash
php artisan error-monitor:xserver-status --date=yesterday
```

From then on the ordinary daily command picks the files up:

```bash
php artisan error-monitor:run
```

The GitHub adapter is optional and is not a dependency of this package.
Schedule after the expected log generation time (07:00 Japan time is the core
guide's example), and verify that both expected file dates are present. Running
at 05:00 still targets yesterday and may leave its coverage incomplete; it does
not switch to the day before yesterday. Missing files are skipped, so a success
exit code alone does not prove full coverage.

See the core's [scheduling guide](https://github.com/ashita-planning/laravel-error-monitor/blob/main/docs/scheduler.md)
and [maintenance guide](https://github.com/ashita-planning/laravel-error-monitor/blob/main/docs/maintenance.md).

## Configuration

| Key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `enabled` | `ERROR_MONITOR_XSERVER_ENABLED` | `false` | Off unless the host actually stores logs this way. |
| `id` | `ERROR_MONITOR_XSERVER_SOURCE_ID` | `xserver` | Identifier in the core's source registry. |
| `server_id` | `XSERVER_SERVER_ID` | – | Account identifier, e.g. `sv00000`. |
| `domains` | `XSERVER_DOMAIN` | – | One or more domains, comma separated. |
| `log_base_path` | `XSERVER_LOG_BASE_PATH` | `/home/{server_id}/{domain}/log` | Directory template. |
| `timezone` | `XSERVER_LOG_TIMEZONE` | `Asia/Tokyo` | The server's own timezone — not the application's. |
| `file_date_basis` | `XSERVER_LOG_FILE_DATE_BASIS` | `end` | Filename date identifies the rotation start (`start`) or end (`end`). |
| `collect_access_log` | `XSERVER_COLLECT_ACCESS_LOG` | `true` | Offer access logs. |
| `collect_error_log` | `XSERVER_COLLECT_ERROR_LOG` | `true` | Offer error logs. |
| `register_access_log_pattern` | `XSERVER_REGISTER_ACCESS_LOG_PATTERN` | `true` | Teach the core's parser the virtual-host prefix. |

## What XServer's logs actually look like

Both kinds live in `/home/{server_id}/{domain}/log/` and are written around
06:00 each morning:

```
{domain}.access_log_YYYYMMDD.gz
{domain}.error_log_YYYYMMDD.gz
```

### Configure the filename date convention

The default `end` preserves the adapter's historical convention and candidate
selection. If the account names files by their **start date**, set:

```dotenv
XSERVER_LOG_FILE_DATE_BASIS=start
```

The guided setup also accepts `--file-date-basis=start` and preserves existing
settings. For an existing published config, add the `file_date_basis` key from
this package's config; an existing hard-coded key must be updated explicitly.
Rebuild the application's configuration cache if used.

| Convention | Candidate filename dates for target day D | Coverage of a file dated F |
| --- | --- | --- |
| `end` (default) | D, D+1 | F-1 at boundary → F at boundary |
| `start` | D-1, D | F at boundary → F+1 at boundary |

Coverage ends are exclusive. The retained boundaries are 04:00 for access and
03:00 for error, in `timezone`; access and error are treated separately.
For example, synthetic start-date files named `20001231` and `20010101` cover
the early and daytime portions of `2001-01-01`. No `20010102` file is expected.

Verify the filename convention **and each kind's rotation time** against the
account's actual specification before switching. These settings and synthetic
tests do not prove that every XServer environment uses these hours or either
particular naming convention. Other rotation hours are not configurable here.
An unknown convention is rejected rather than silently selecting the wrong pair.

`error-monitor:xserver-status --date=2001-01-01 --json` reports
`file_date_basis`, available files with coverage metadata, and required files
that are missing or unreadable. Missing files do not prove why they are absent.
This library change does not update or enable any consuming application.

Each file reports its configured bounds in `metadata`, so nothing downstream has to
guess:

```php
[
    'provider' => 'xserver',
    'domain' => 'example.com',
    'server_identifier' => 'sv00000',
    'xserver_file_date' => '2026-08-04',
    'xserver_file_date_basis' => 'end',
    'xserver_log_kind' => 'access',
    'coverage_start_local' => '2026-08-03 04:00:00',
    'coverage_end_local' => '2026-08-04 04:00:00',
    'timezone' => 'Asia/Tokyo',
    'log_format' => 'xserver_vhost_combined',
]
```

`target_date` is the date **in the file name**. It identifies the file; what the
file holds is the coverage pair above.

### A missing file is usually normal

Today's log does not exist until the next morning, and XServer states that the
error log is not generated at all while the account's disk usage is above 80%.
Absence is reported by `error-monitor:xserver-status` and skipped, never treated
as a failure.

### The access log is not plain Combined format

XServer prefixes every line with the virtual host:

```
example.com 192.0.2.0 - - [11/Jul/2013:12:09:17 +0900] "GET / HTTP/1.0" 200 2602 "-" "Mozilla/5.0 ..."
```

Rather than shipping a second parser, this package appends a named-group pattern
to the core's `error-monitor.apache_access_patterns`. The core's existing Apache
parser then reads the format as-is. Set `register_access_log_pattern` to `false`
if your core configuration already carries an equivalent pattern.

## What this package deliberately does not do

- **It does not decompress.** The core streams `.gz` through `gzopen`, so files
  are handed over compressed. Expanding them here would duplicate that work and
  leave a plaintext copy of somebody's access log on disk.
- **It does not modify or delete the originals.** They belong to the hosting
  account.
- **It holds no credentials.** This version reads files already present in the
  account's home directory. XServer API access, SSH/SFTP retrieval, local copy
  retention and multi-server collection are deliberately left for later.
- **It contains no analysis.** Parsing, masking, fingerprinting and aggregation
  all belong to the core.

Path traversal, symlink handling and deciding which directories may be read are
this package's responsibility rather than the core's — it is the side that knows
what "allowed" means here. The current version only ever builds paths from the
configured template, so it reads nothing an operator has not named.

## Security

This repository contains **no real server id, domain, log, key or credential**.
The test fixtures use `sv00000`, `example.invalid` and documentation IP ranges
(`203.0.113.0/24`) only, and `.env.example` holds placeholders. Keep it that
way: real values belong in an environment file that is never committed.

## Development

```bash
composer update
composer test
composer check   # Pint, PHPStan and PHPUnit
```

The test fixtures reproduce the official file names under a fake
`tests/Fixtures/home/sv00000/example.invalid/log/` tree, including one
deliberately absent error log so the "not generated" case stays covered.

## License

MIT.
