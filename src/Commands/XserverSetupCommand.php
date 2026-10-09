<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Commands;

use Apkk\LaravelErrorMonitor\Services\SetupFileManager;
use Apkk\LaravelErrorMonitorXserver\Support\XserverLogFile;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Throwable;

final class XserverSetupCommand extends Command
{
    protected $signature = 'error-monitor:xserver-setup {--server-id= : Hosting account identifier} {--domain= : Comma-separated domains} {--file-date-basis= : Filename date convention: start or end} {--enable : Enable collection in missing settings} {--dry-run : Preview without writing} {--json : Machine-readable output}';

    protected $description = 'Prepare missing XServer settings without retrieving or reading logs.';

    public function handle(SetupFileManager $files, Application $application): int
    {
        $server = (string) ($this->option('server-id') ?? config('error-monitor-xserver.server_id', ''));
        $domains = (string) ($this->option('domain') ?? implode(',', (array) config('error-monitor-xserver.domains', [])));
        $basis = (string) ($this->option('file-date-basis') ?? config('error-monitor-xserver.file_date_basis', 'end'));
        if (! in_array($basis, [XserverLogFile::DATE_START, XserverLogFile::DATE_END], true)) {
            $this->error('File date basis must be start or end.');

            return self::INVALID;
        }
        $valid = $server === '' || (bool) preg_match('/^[A-Za-z0-9_-]+$/D', $server);
        foreach ($domains === '' ? [] : explode(',', $domains) as $domain) {
            $valid = $valid && (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', trim($domain));
        }
        if (! $valid || ($this->option('enable') && ($server === '' || $domains === ''))) {
            $this->error('Use a server identifier and domains without path separators; both are required to enable collection.');

            return self::INVALID;
        }
        try {
            $result = $files->setup(
                $application->environmentFilePath(), config_path('error-monitor-xserver.php'),
                __DIR__.'/../../config/error-monitor-xserver.php', [
                    'ERROR_MONITOR_XSERVER_ENABLED' => $this->option('enable') ? 'true' : 'false',
                    'XSERVER_SERVER_ID' => $server,
                    'XSERVER_DOMAIN' => $domains,
                    'XSERVER_LOG_FILE_DATE_BASIS' => $basis,
                ], (bool) $this->option('dry-run'),
            );
        } catch (Throwable) {
            $this->error('Setup failed. Check file permissions and target files; existing settings were not overwritten.');

            return self::FAILURE;
        }
        $result['next_steps'] = ['Existing values are preserved. Rebuild configuration cache if used.',
            'Enable hosting log storage, run error-monitor:xserver-status --date=yesterday and schedule after log generation.'];
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
