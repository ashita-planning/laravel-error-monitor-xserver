<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Tests\Feature;

use Apkk\LaravelErrorMonitor\Commands\RunErrorMonitorCommand;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;
use Apkk\LaravelErrorMonitor\ErrorMonitorServiceProvider;
use Apkk\LaravelErrorMonitor\Models\ErrorMonitorEvent;
use Apkk\LaravelErrorMonitor\Services\LogSourceRegistry;
use Apkk\LaravelErrorMonitor\Support\LogSource;
use Apkk\LaravelErrorMonitorXserver\Tests\TestCase;
use Apkk\LaravelErrorMonitorXserver\XserverLogSource;

final class XserverLogSourceTest extends TestCase
{
    private const DAY = '2026-08-03';

    public function test_it_registers_itself_with_the_core(): void
    {
        $registry = app(LogSourceRegistry::class);

        $this->assertTrue($registry->has('xserver'));
        $this->assertInstanceOf(XserverLogSource::class, $registry->get('xserver'));
    }

    public function test_it_offers_the_day_and_the_following_morning(): void
    {
        $files = $this->collect();
        $names = array_map(static fn (CollectedLogFileData $file): string => basename($file->path), $files);
        sort($names);

        // The error log dated the 3rd is missing from the fixture on purpose,
        // which is the disk-usage case the manual documents.
        $this->assertSame([
            'example.invalid.access_log_20260803.gz',
            'example.invalid.access_log_20260804.gz',
            'example.invalid.error_log_20260804.gz',
        ], $names);
    }

    public function test_a_file_that_was_never_written_is_a_normal_absence(): void
    {
        $source = app(XserverLogSource::class);
        iterator_to_array($source->collect($this->window()), false);

        $this->assertContains(
            $this->logDirectory().'/example.invalid.error_log_20260803.gz',
            $source->missing(),
            'XServer does not write the error log while disk usage is above 80%.',
        );
    }

    public function test_each_file_carries_the_source_its_parser_claims(): void
    {
        $sources = array_map(static fn (CollectedLogFileData $file): string => $file->source, $this->collect());

        $this->assertContains(LogSource::APACHE_ACCESS, $sources);
        $this->assertContains(LogSource::APACHE_ERROR, $sources);
    }

    public function test_files_are_handed_over_still_compressed_and_hashed(): void
    {
        $file = $this->collect()[0];

        $this->assertTrue($file->compressed, 'The core streams .gz itself.');
        $this->assertSame(hash_file('sha256', $file->path), $file->fileHash);
        $this->assertSame(64, strlen($file->fileHash));
    }

    public function test_the_metadata_states_the_real_coverage(): void
    {
        $access = $this->fileNamed('example.invalid.access_log_20260804.gz');
        $error = $this->fileNamed('example.invalid.error_log_20260804.gz');

        $this->assertSame('2026-08-03 04:00:00', $access->metadata['coverage_start_local']);
        $this->assertSame('2026-08-04 04:00:00', $access->metadata['coverage_end_local']);
        // Three, not four: the two kinds have different boundaries.
        $this->assertSame('2026-08-03 03:00:00', $error->metadata['coverage_start_local']);
        $this->assertSame('2026-08-04 03:00:00', $error->metadata['coverage_end_local']);

        $this->assertSame('xserver', $access->metadata['provider']);
        $this->assertSame('Asia/Tokyo', $access->metadata['timezone']);
    }

    public function test_the_target_date_is_the_file_date_not_the_covered_day(): void
    {
        $file = $this->fileNamed('example.invalid.access_log_20260804.gz');

        // `targetDate` identifies the file; what it holds is in the metadata,
        // because reading it as "midnight to midnight" would be wrong.
        $this->assertSame('2026-08-04', $file->targetDate->format('Y-m-d'));
        $this->assertSame('2026-08-04', $file->metadata['xserver_file_date']);
    }

    public function test_a_disabled_adapter_offers_nothing(): void
    {
        config()->set('error-monitor-xserver.enabled', false);
        $this->app->forgetInstance(XserverLogSource::class);

        $this->assertSame([], $this->collect());
    }

    public function test_an_unconfigured_adapter_offers_nothing(): void
    {
        // The package may be installed on a host that is not XServer at all.
        config()->set('error-monitor-xserver.server_id', '');
        $this->app->forgetInstance(XserverLogSource::class);

        $this->assertSame([], $this->collect());
    }

    public function test_a_single_kind_can_be_collected(): void
    {
        config()->set('error-monitor-xserver.collect_error_log', false);
        $this->app->forgetInstance(XserverLogSource::class);

        $sources = array_map(static fn (CollectedLogFileData $file): string => $file->source, $this->collect());

        $this->assertSame([LogSource::APACHE_ACCESS], array_values(array_unique($sources)));
    }

    public function test_the_core_learns_the_virtual_host_format_rather_than_getting_a_second_parser(): void
    {
        /** @var array<int, string> $patterns */
        $patterns = (array) config('error-monitor.apache_access_patterns', []);

        $this->assertNotSame([], $patterns);
        $this->assertStringContainsString('vhost', $patterns[0]);
    }

    public function test_the_whole_path_reaches_the_database(): void
    {
        $this->artisan('error-monitor:run', ['--date' => self::DAY])
            ->assertExitCode(RunErrorMonitorCommand::EXIT_SUCCESS);

        $stored = ErrorMonitorEvent::query()->get();

        $this->assertGreaterThan(0, $stored->count());
        $this->assertContains(LogSource::APACHE_ACCESS, $stored->pluck('source')->all());
        $this->assertContains(LogSource::APACHE_ERROR, $stored->pluck('source')->all());
    }

    public function test_the_leading_virtual_host_does_not_confuse_the_parser(): void
    {
        $this->artisan('error-monitor:run', ['--date' => self::DAY])->run();

        $access = ErrorMonitorEvent::query()->where('source', LogSource::APACHE_ACCESS)->get();

        // Read through the vhost prefix: the client is the second field, the
        // route comes from the request, and the 200 and 404 are excluded.
        $this->assertContains('/orders/{id}', $access->pluck('route')->all());
        $this->assertSame([500], array_values(array_unique($access->pluck('status_code')->all())));
    }

    public function test_the_status_command_separates_absent_from_misconfigured(): void
    {
        $this->artisan('error-monitor:xserver-status', ['--date' => self::DAY, '--json' => true])
            ->expectsOutputToContain('"missing"')
            ->assertExitCode(0);
    }

    public function test_the_adapter_is_reachable_through_the_container_tag(): void
    {
        $tagged = iterator_to_array($this->app->tagged(ErrorMonitorServiceProvider::SERVER_LOG_SOURCE_TAG), false);

        $this->assertCount(1, $tagged);
        $this->assertInstanceOf(XserverLogSource::class, $tagged[0]);
    }

    /** @return array<int, CollectedLogFileData> */
    private function collect(): array
    {
        /** @var array<int, CollectedLogFileData> $files */
        $files = iterator_to_array(app(XserverLogSource::class)->collect($this->window()), false);

        return $files;
    }

    private function fileNamed(string $name): CollectedLogFileData
    {
        foreach ($this->collect() as $file) {
            if (basename($file->path) === $name) {
                return $file;
            }
        }

        $this->fail(sprintf('[%s] was not offered.', $name));
    }

    private function window(): AnalysisWindowData
    {
        return AnalysisWindowData::forDate(self::DAY, 'Asia/Tokyo');
    }

    private function logDirectory(): string
    {
        return strtr($this->logBasePath(), ['{server_id}' => 'sv00000', '{domain}' => 'example.invalid']);
    }
}
