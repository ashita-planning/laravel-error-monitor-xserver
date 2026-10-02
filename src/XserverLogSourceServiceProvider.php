<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver;

use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitorXserver\Commands\XserverSetupCommand;
use Apkk\LaravelErrorMonitorXserver\Commands\XserverStatusCommand;
use Apkk\LaravelErrorMonitorXserver\Support\XserverLogFile;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the XServer log source with the core package.
 *
 * The whole integration is one container tag. Nothing here reaches into the
 * core's pipeline, and the core learns nothing about XServer beyond the files
 * it is handed.
 */
final class XserverLogSourceServiceProvider extends ServiceProvider
{
    /**
     * The one thing plain Combined format cannot express.
     *
     * XServer writes the virtual host name in front of every access log line.
     * The core accepts extra named-group patterns for exactly this reason, so
     * the format is taught to the parser that already exists rather than
     * answered with a copy of it living here.
     */
    private const ACCESS_LOG_PATTERN = '/^(?P<vhost>[A-Za-z0-9.\-_]+)\s+(?P<client>\S+)\s+(?P<identity>\S+)\s+(?P<user>\S+)\s+\[(?P<time>[^\]]+)\]\s+"(?P<request>(?:[^"\\\\]|\\\\.)*)"\s+(?P<status>\d{3})\s+(?P<bytes>\d+|-)(?:\s+"(?P<referer>(?:[^"\\\\]|\\\\.)*)"\s+"(?P<agent>(?:[^"\\\\]|\\\\.)*)")?/';

    private const CONFIG_PATH = __DIR__.'/../config/error-monitor-xserver.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'error-monitor-xserver');

        $this->app->singleton(XserverLogSource::class, static function (Application $app): XserverLogSource {
            $config = $app->make('config');

            /** @var array<int, string> $domains */
            $domains = (array) $config->get('error-monitor-xserver.domains', []);

            $kinds = [];

            if ((bool) $config->get('error-monitor-xserver.collect_access_log', true)) {
                $kinds[] = XserverLogFile::ACCESS;
            }

            if ((bool) $config->get('error-monitor-xserver.collect_error_log', true)) {
                $kinds[] = XserverLogFile::ERROR;
            }

            return new XserverLogSource(
                id: (string) $config->get('error-monitor-xserver.id', 'xserver'),
                serverId: (string) $config->get('error-monitor-xserver.server_id', ''),
                domains: array_values($domains),
                basePathTemplate: (string) $config->get('error-monitor-xserver.log_base_path', ''),
                timezone: (string) $config->get('error-monitor-xserver.timezone', 'Asia/Tokyo'),
                kinds: $kinds,
                enabled: (bool) $config->get('error-monitor-xserver.enabled', false),
            );
        });

        // Tagging is unconditional and the registry resolves lazily; `enabled`
        // is not readable yet while providers register. A disabled adapter is
        // handled where the source is asked for files.
        $this->app->tag([XserverLogSource::class], ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG);
    }

    public function boot(): void
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('error-monitor-xserver.php'),
        ], 'error-monitor-xserver-config');

        $this->registerAccessLogPattern();

        if ($this->app->runningInConsole()) {
            $this->commands([XserverStatusCommand::class, XserverSetupCommand::class]);
        }
    }

    /** Append the virtual host aware pattern to the core's parser configuration. */
    private function registerAccessLogPattern(): void
    {
        $config = $this->app->make('config');

        if (! (bool) $config->get('error-monitor-xserver.register_access_log_pattern', true)) {
            return;
        }

        /** @var array<int, string> $patterns */
        $patterns = (array) $config->get('error-monitor.apache_access_patterns', []);

        if (in_array(self::ACCESS_LOG_PATTERN, $patterns, true)) {
            return;
        }

        $patterns[] = self::ACCESS_LOG_PATTERN;

        $config->set('error-monitor.apache_access_patterns', array_values($patterns));
    }
}
