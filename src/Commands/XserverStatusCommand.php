<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Commands;

use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitorXserver\XserverLogSource;
use Illuminate\Console\Command;

/**
 * Shows which log files the adapter would offer for a day, and which are absent.
 *
 * Worth having because the two failure modes here look identical from the
 * outside: a path template that is subtly wrong and a day whose logs have not
 * been written yet both produce nothing. This says which it is.
 */
final class XserverStatusCommand extends Command
{
    protected $signature = 'error-monitor:xserver-status
        {--date= : Day to resolve, e.g. 2026-08-03 or yesterday. Defaults to yesterday}
        {--json : Output as JSON}';

    protected $description = 'Show the XServer log files available for a given day.';

    public function handle(XserverLogSource $source): int
    {
        /** @var string|null $date */
        $date = $this->option('date');
        $timezone = (string) config('error-monitor-xserver.timezone', 'Asia/Tokyo');
        $window = AnalysisWindowData::forDate($date ?? 'yesterday', $timezone);

        $files = [];

        foreach ($source->collect($window) as $file) {
            $files[] = $file->toArray();
        }

        $status = [
            'enabled' => (bool) config('error-monitor-xserver.enabled', false),
            'source_id' => $source->id(),
            'window' => $window->toArray(),
            'available' => $files,
            // Absence is routine: XServer writes the logs around 06:00, and the
            // error log is not written at all while disk usage is above 80%.
            'missing' => $source->missing(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('%d file(s) available, %d not written yet.', count($files), count($status['missing'])));

        foreach ($files as $file) {
            $this->components->twoColumnDetail(
                basename((string) $file['path']),
                sprintf('%s, covers %s to %s', $file['source'], $file['metadata']['coverage_start_local'], $file['metadata']['coverage_end_local']),
            );
        }

        foreach ($status['missing'] as $path) {
            $this->components->twoColumnDetail(basename((string) $path), 'not present');
        }

        return self::SUCCESS;
    }
}
