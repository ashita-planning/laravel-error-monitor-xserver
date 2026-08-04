# Laravel Error Monitor — XServer adapter

`ashita-planning/laravel-error-monitor-xserver` lets
[`ashita-planning/laravel-error-monitor`](https://github.com/ashita-planning/laravel-error-monitor)
read the Apache logs XServer stores in a hosting account's own home directory.

The core package knows nothing about XServer, and this one contains no analysis:
it resolves paths, hands readable files over through the core's
`ServerLogSource` contract, and stops there.

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, or 12
- `ashita-planning/laravel-error-monitor`

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

## Configuration

| Key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `enabled` | `ERROR_MONITOR_XSERVER_ENABLED` | `false` | Off unless the host actually stores logs this way. |
| `id` | `ERROR_MONITOR_XSERVER_SOURCE_ID` | `xserver` | Identifier in the core's source registry. |
| `server_id` | `XSERVER_SERVER_ID` | – | Account identifier, e.g. `sv00000`. |
| `domains` | `XSERVER_DOMAIN` | – | One or more domains, comma separated. |
| `log_base_path` | `XSERVER_LOG_BASE_PATH` | `/home/{server_id}/{domain}/log` | Directory template. |
| `timezone` | `XSERVER_LOG_TIMEZONE` | `Asia/Tokyo` | The server's own timezone — not the application's. |
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

### A file is named after the morning it was written

This is the detail worth internalising, because getting it wrong loses data
silently:

| File | Covers |
| --- | --- |
| `…access_log_20260804.gz` | 2026-08-03 **04:00** → 2026-08-04 **04:00** |
| `…error_log_20260804.gz` | 2026-08-03 **03:00** → 2026-08-04 **03:00** |

So the file dated the 4th is mostly *about the 3rd*, and the two kinds do not
even share a boundary. Investigating a single day therefore means reading **two
files per kind** — the day's own and the next morning's — which is what this
adapter offers. Reading only `…_20260803.gz` to investigate the 3rd would miss
everything after 04:00 that day.

Each file reports its real bounds in `metadata`, so nothing downstream has to
guess:

```php
[
    'provider' => 'xserver',
    'domain' => 'example.com',
    'server_identifier' => 'sv00000',
    'xserver_file_date' => '2026-08-04',
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
