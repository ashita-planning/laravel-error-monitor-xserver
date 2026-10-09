<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Tests\Feature;

use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitorXserver\Support\XserverLogFile;
use Apkk\LaravelErrorMonitorXserver\Tests\TestCase;
use Apkk\LaravelErrorMonitorXserver\XserverLogSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileDateBasisTest extends TestCase
{
    #[DataProvider('conventions')]
    public function test_both_conventions_collect_and_count_only_the_target_day_once(string $basis, string $date): void
    {
        config()->set('error-monitor.retention_days', 0);
        config()->set('error-monitor.analysis.context_before_seconds', 0);
        config()->set('error-monitor.analysis.context_after_seconds', 0);
        // Synthetic rotations only; these hours do not establish host behavior.
        $directory = $this->fixtures($basis, $date);
        try {
            $source = app(XserverLogSource::class);
            $window = AnalysisWindowData::forDate($date, 'Asia/Tokyo');
            $files = iterator_to_array($source->collect($window));
            $this->assertCount(4, $files);
            $this->assertSame([], $source->missing());
            foreach ($files as $file) {
                $this->assertSame($basis, $file->metadata['xserver_file_date_basis']);
            }
            $hashes = array_map(static fn ($file) => hash_file('sha256', $file->path), $files);
            ErrorMonitorEvent::query()->delete();
            for ($run = 0; $run < 2; $run++) {
                $this->artisan('error-monitor:run', ['--date' => $date, '--skip-github' => true])->assertSuccessful();
                $events = ErrorMonitorEvent::query()->get();
                $this->assertSame(4, (int) $events->sum('occurrence_count'));
                $this->assertEqualsCanonicalizing(['/early-access', '/day-access'], $events->where('source', 'apache_access')->pluck('route')->all());
                $errors = $events->where('source', 'apache_error');
                $this->assertSame(2, (int) $errors->sum('occurrence_count'));
                $this->assertStringNotContainsString('outside', $errors->pluck('normalized_message')->implode(' '));
                $this->assertStringContainsString('/early-error', $errors->pluck('normalized_message')->implode(' '));
                $this->assertStringContainsString('/day-error', $errors->pluck('normalized_message')->implode(' '));
                $this->assertSame([$date], $events->pluck('detected_date')->map(static fn ($day) => $day->format('Y-m-d'))->unique()->values()->all());
            }
            $this->assertSame($hashes, array_map(static fn ($file) => hash_file('sha256', $file->path), $files));
            $this->assertCount(4, glob($directory.'/*'));
        } finally {
            $this->removeFixtures($directory);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function conventions(): iterable
    {
        foreach (['start', 'end'] as $basis) {
            foreach (['2001-01-01', '2001-02-01'] as $date) {
                yield $basis.'-'.$date => [$basis, $date];
            }
        }
    }

    public function test_status_reports_selected_convention_and_only_required_missing_or_unreadable_files(): void
    {
        $directory = $this->fixtures('start', '2001-01-01');
        $unreadable = $directory.'/example.invalid.error_log_20001231.gz';
        try {
            unlink($directory.'/example.invalid.access_log_20001231.gz');
            chmod($unreadable, 0000);
            $this->assertFalse(is_readable($unreadable));
            Artisan::call('error-monitor:xserver-status', ['--date' => '2001-01-01', '--json' => true]);
            $status = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('start', $status['file_date_basis']);
            $this->assertCount(2, $status['available']);
            $this->assertEqualsCanonicalizing([
                $directory.'/example.invalid.access_log_20001231.gz', $unreadable,
            ], $status['missing']);
            $this->assertStringNotContainsString('20010102.gz', Artisan::output());
            $this->artisan('error-monitor:xserver-status', ['--date' => '2001-01-01'])
                ->expectsOutputToContain('missing or unreadable')->assertSuccessful();
        } finally {
            chmod($unreadable, 0600);
            $this->removeFixtures($directory);
        }
    }

    public function test_a_utc_window_selects_the_japan_calendar_day(): void
    {
        $directory = $this->fixtures('start', '2001-01-01');
        try {
            $window = AnalysisWindowData::between('2000-12-31 15:00:00', '2001-01-01 14:59:59', 'UTC');
            $files = iterator_to_array(app(XserverLogSource::class)->collect($window));
            $this->assertCount(4, $files);
            $this->assertSame([], app(XserverLogSource::class)->missing());
            $this->assertEqualsCanonicalizing(['2000-12-31', '2001-01-01'], array_values(array_unique(array_map(static fn ($file) => $file->targetDate->format('Y-m-d'), $files))));
        } finally {
            $this->removeFixtures($directory);
        }
    }

    private function fixtures(string $basis, string $date): string
    {
        $directory = sys_get_temp_dir().'/xserver-basis-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        config()->set('error-monitor-xserver.file_date_basis', $basis);
        config()->set('error-monitor-xserver.log_base_path', $directory);
        $this->app->forgetInstance(XserverLogSource::class);
        $day = new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo'));
        foreach (['access', 'error'] as $kind) {
            $candidates = XserverLogFile::candidatesFor($kind, $day, 'sv00000', 'example.invalid', $directory, $basis);
            foreach ($candidates as $index => $file) {
                $times = $index === 0
                    ? [[$day->modify('-1 day')->setTime(23, 59), '/outside-before'], [$day->setTime(0, 15), '/early-'.$kind]]
                    : [[$day->setTime(12, 0), '/day-'.$kind], [$day->modify('+1 day')->setTime(0, 0), '/outside-after']];
                $lines = '';
                foreach ($times as [$time, $route]) {
                    $lines .= $kind === 'access'
                        ? sprintf("example.invalid 203.0.113.20 - - [%s +0900] \"GET %s HTTP/1.1\" 500 100 \"-\" \"synthetic\"\n", $time->format('d/M/Y:H:i:s'), $route)
                        : sprintf("[%s] [proxy:error] [pid 123] [client 203.0.113.20:1234] AH01071: Synthetic failure, request: GET %s HTTP/1.1\n", $time->format('D M d H:i:s Y'), $route);
                }
                file_put_contents($file->path, gzencode($lines));
            }
        }

        return $directory;
    }

    private function removeFixtures(string $directory): void
    {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
