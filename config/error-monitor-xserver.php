<?php

declare(strict_types=1);

return [
    // Off unless an installation says otherwise: the adapter is only useful on
    // a host whose logs actually live where XServer puts them.
    'enabled' => (bool) env('ERROR_MONITOR_XSERVER_ENABLED', false),

    // Identifier this source registers under in the core's registry.
    'id' => (string) env('ERROR_MONITOR_XSERVER_SOURCE_ID', 'xserver'),

    'server_id' => (string) env('XSERVER_SERVER_ID', ''),

    // One or more domains, comma separated.
    'domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('XSERVER_DOMAIN', '')),
    ))),

    // `{server_id}` and `{domain}` are substituted. Overridable so the adapter
    // can be pointed at a staging copy without touching the code.
    'log_base_path' => (string) env('XSERVER_LOG_BASE_PATH', '/home/{server_id}/{domain}/log'),

    // XServer names and rotates its logs in Japan time. This is not the
    // application's timezone and should not follow it.
    'timezone' => (string) env('XSERVER_LOG_TIMEZONE', 'Asia/Tokyo'),

    // Which of the two log kinds to offer.
    'collect_access_log' => (bool) env('XSERVER_COLLECT_ACCESS_LOG', true),
    'collect_error_log' => (bool) env('XSERVER_COLLECT_ERROR_LOG', true),

    // XServer prefixes every access log line with the virtual host name, which
    // the plain Combined format does not allow for. The core supports extra
    // named-group patterns, so the format is taught rather than reimplemented -
    // no parser is duplicated in this package. Turn off if the core config
    // already carries an equivalent pattern.
    'register_access_log_pattern' => (bool) env('XSERVER_REGISTER_ACCESS_LOG_PATTERN', true),
];
