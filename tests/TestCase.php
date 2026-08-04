<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Tests;

use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitorXserver\XserverLogSourceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Fixture tree shaped exactly like a real account's log directory. */
    protected function logBasePath(): string
    {
        return __DIR__.'/Fixtures/home/{server_id}/{domain}/log';
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [ErrorMonitorServiceProvider::class, XserverLogSourceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');

        // The core's own collectors must find nothing: everything under test
        // arrives through the adapter.
        $app['config']->set('error-monitor.laravel_log_path', __DIR__.'/Fixtures/no-logs/laravel.log');
        $app['config']->set('error-monitor.apache_access_log_path', __DIR__.'/Fixtures/no-logs/access.log');
        $app['config']->set('error-monitor.apache_error_log_path', __DIR__.'/Fixtures/no-logs/error.log');
        $app['config']->set('error-monitor.timezone', 'Asia/Tokyo');

        $app['config']->set('error-monitor-xserver.enabled', true);
        $app['config']->set('error-monitor-xserver.server_id', 'sv00000');
        $app['config']->set('error-monitor-xserver.domains', ['example.invalid']);
        $app['config']->set('error-monitor-xserver.log_base_path', $this->logBasePath());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/ashita-planning/laravel-error-monitor/database/migrations');
    }
}
