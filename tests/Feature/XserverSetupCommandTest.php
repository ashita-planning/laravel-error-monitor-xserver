<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Tests\Feature;

use Apkk\LaravelErrorMonitorXserver\Tests\TestCase;

final class XserverSetupCommandTest extends TestCase
{
    public function test_setup_previews_then_adds_settings_without_reading_logs(): void
    {
        $directory = sys_get_temp_dir().'/xserver-setup-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->app->useEnvironmentPath($directory);
        $this->app->useConfigPath($directory);
        try {
            $options = ['--server-id' => 'sv00000', '--domain' => 'example.invalid', '--enable' => true, '--file-date-basis' => 'start'];
            $this->artisan('error-monitor:xserver-setup', $options + ['--dry-run' => true])->assertSuccessful();
            $this->assertFileDoesNotExist($directory.'/.env');
            $this->artisan('error-monitor:xserver-setup', $options)->assertSuccessful();
            $content = file_get_contents($directory.'/.env');
            $this->assertStringContainsString('XSERVER_LOG_FILE_DATE_BASIS="start"', $content);
            $this->artisan('error-monitor:xserver-setup', array_replace($options, ['--file-date-basis' => 'end']))->assertSuccessful();
            $this->assertSame($content, file_get_contents($directory.'/.env'));
            $this->assertStringContainsString('XSERVER_DOMAIN="example.invalid"', $content);
        } finally {
            @unlink($directory.'/.env');
            @unlink($directory.'/error-monitor-xserver.php');
            rmdir($directory);
        }
    }

    public function test_unknown_file_date_basis_is_rejected_before_writing(): void
    {
        $this->artisan('error-monitor:xserver-setup', ['--file-date-basis' => 'typo', '--dry-run' => true])->assertExitCode(2);
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->artisan('error-monitor:xserver-setup', ['--server-id' => '../sv00000', '--dry-run' => true])->assertExitCode(2);
    }
}
