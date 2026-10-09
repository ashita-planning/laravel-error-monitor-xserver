<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Tests\Unit;

use Apkk\LaravelErrorMonitor\Support\LogSource;
use Apkk\LaravelErrorMonitorXserver\Support\XserverLogFile;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XserverLogFileTest extends TestCase
{
    private const TEMPLATE = '/home/{server_id}/{domain}/log';

    public function test_it_builds_the_documented_file_name(): void
    {
        $date = new DateTimeImmutable('2016-01-01');

        $this->assertSame(
            '/home/sv00000/example.invalid/log/example.invalid.access_log_20160101.gz',
            XserverLogFile::pathFor(XserverLogFile::ACCESS, $date, 'sv00000', 'example.invalid', self::TEMPLATE),
        );

        $this->assertSame(
            '/home/sv00000/example.invalid/log/example.invalid.error_log_20160101.gz',
            XserverLogFile::pathFor(XserverLogFile::ERROR, $date, 'sv00000', 'example.invalid', self::TEMPLATE),
        );
    }

    public function test_an_access_file_covers_four_to_four(): void
    {
        $file = $this->file(XserverLogFile::ACCESS, '2026-08-04');
        $timezone = new DateTimeZone('Asia/Tokyo');

        // Named after the morning it was written, not the day it describes.
        $this->assertSame('2026-08-03 04:00:00', $file->coverageStart($timezone)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 04:00:00', $file->coverageEnd($timezone)->format('Y-m-d H:i:s'));
    }

    public function test_an_error_file_covers_three_to_three(): void
    {
        $file = $this->file(XserverLogFile::ERROR, '2026-08-04');
        $timezone = new DateTimeZone('Asia/Tokyo');

        // The two kinds do not even share a boundary.
        $this->assertSame('2026-08-03 03:00:00', $file->coverageStart($timezone)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 03:00:00', $file->coverageEnd($timezone)->format('Y-m-d H:i:s'));
    }

    public function test_covering_one_day_needs_that_day_and_the_next(): void
    {
        $candidates = XserverLogFile::candidatesFor(
            XserverLogFile::ACCESS,
            new DateTimeImmutable('2026-08-03'),
            'sv00000',
            'example.invalid',
            self::TEMPLATE,
        );

        // Everything on the 3rd from 04:00 onwards lives in the file named
        // after the 4th, so asking only for the 3rd would lose most of the day.
        $this->assertSame(['20260803', '20260804'], array_map(
            static fn (XserverLogFile $file): string => $file->fileDate->format('Ymd'),
            $candidates,
        ));
    }

    public function test_each_kind_maps_to_the_core_source_that_parses_it(): void
    {
        $this->assertSame(LogSource::APACHE_ACCESS, $this->file(XserverLogFile::ACCESS, '2026-08-04')->source());
        $this->assertSame(LogSource::APACHE_ERROR, $this->file(XserverLogFile::ERROR, '2026-08-04')->source());
    }

    public function test_the_metadata_spells_out_what_the_file_holds(): void
    {
        $metadata = $this->file(XserverLogFile::ACCESS, '2026-08-04')->metadata(new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('xserver', $metadata['provider']);
        $this->assertSame('example.invalid', $metadata['domain']);
        $this->assertSame('sv00000', $metadata['server_identifier']);
        $this->assertSame('2026-08-04', $metadata['xserver_file_date']);
        $this->assertSame('2026-08-03 04:00:00', $metadata['coverage_start_local']);
        $this->assertSame('2026-08-04 04:00:00', $metadata['coverage_end_local']);
        $this->assertSame('Asia/Tokyo', $metadata['timezone']);
        $this->assertSame('xserver_vhost_combined', $metadata['log_format']);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->file('nonsense', '2026-08-04');
    }

    public function test_start_date_candidates_and_coverage_cross_month_and_year_boundaries(): void
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        foreach (['2001-01-01' => '20001231', '2001-02-01' => '20010131'] as $date => $previous) {
            foreach (['access' => '04', 'error' => '03'] as $kind => $hour) {
                // The local target day differs from its UTC calendar date.
                $day = (new DateTimeImmutable($date.' 00:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'))->setTimezone($timezone);
                $files = XserverLogFile::candidatesFor($kind, $day, 'sv00000', 'example.invalid', self::TEMPLATE, 'start');
                $this->assertSame([$previous, $day->format('Ymd')], array_map(static fn (XserverLogFile $file): string => $file->fileDate->format('Ymd'), $files));
                $this->assertSame($day->modify('-1 day')->format('Y-m-d').' '.$hour.':00:00', $files[0]->metadata($timezone)['coverage_start_local']);
                $this->assertSame($date.' '.$hour.':00:00', $files[0]->metadata($timezone)['coverage_end_local']);
                $this->assertSame($date.' '.$hour.':00:00', $files[1]->metadata($timezone)['coverage_start_local']);
                $this->assertSame($day->modify('+1 day')->format('Y-m-d').' '.$hour.':00:00', $files[1]->metadata($timezone)['coverage_end_local']);
            }
        }
    }

    public function test_unknown_file_date_basis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XserverLogFile::candidatesFor('access', new DateTimeImmutable('2001-01-01'), 'sv00000', 'example.invalid', self::TEMPLATE, 'typo');
    }

    private function file(string $kind, string $date): XserverLogFile
    {
        return new XserverLogFile(
            kind: $kind,
            path: '/irrelevant',
            domain: 'example.invalid',
            serverId: 'sv00000',
            fileDate: new DateTimeImmutable($date),
        );
    }
}
