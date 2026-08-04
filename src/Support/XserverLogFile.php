<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver\Support;

use Apkk\LaravelErrorMonitor\Support\LogSource;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One XServer log file: where it lives, and what period it actually holds.
 *
 * The second half is the part worth reading carefully. XServer names a file by
 * the morning it was written, not by the day it describes, and the two kinds do
 * not even share a boundary:
 *
 *   {domain}.access_log_YYYYMMDD.gz   covers YYYY-MM-(DD-1) 04:00 to YYYY-MM-DD 04:00
 *   {domain}.error_log_YYYYMMDD.gz    covers YYYY-MM-(DD-1) 03:00 to YYYY-MM-DD 03:00
 *
 * So a file dated the 4th is mostly about the 3rd, and reading the file dated
 * the 3rd to investigate the 3rd would miss everything after 04:00 that day.
 * {@see XserverLogFile::candidatesFor()} is what makes that not a footgun.
 */
final readonly class XserverLogFile
{
    public const ACCESS = 'access';

    public const ERROR = 'error';

    /** Local hour each kind of file starts and ends at. */
    private const COVERAGE_HOUR = [
        self::ACCESS => 4,
        self::ERROR => 3,
    ];

    /** Core source key each kind is claimed by. */
    private const SOURCE = [
        self::ACCESS => LogSource::APACHE_ACCESS,
        self::ERROR => LogSource::APACHE_ERROR,
    ];

    /**
     * Deliberately typed as a plain string: the guard below is what makes it
     * one of the two known kinds, and it exists because the value can come
     * from configuration.
     *
     * @param  string  $kind  `access` or `error`.
     * @param  DateTimeImmutable  $fileDate  The YYYYMMDD in the file name.
     */
    public function __construct(
        public string $kind,
        public string $path,
        public string $domain,
        public string $serverId,
        public DateTimeImmutable $fileDate,
    ) {
        if (! array_key_exists($kind, self::COVERAGE_HOUR)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a known XServer log kind.', $kind));
        }
    }

    /**
     * Files that together hold every entry of the given local day.
     *
     * Two of them, always: the file named after the day covers only its first
     * few hours, and everything from 03:00 or 04:00 onwards is in the file
     * named after the following morning.
     *
     * @return array<int, self>
     */
    public static function candidatesFor(
        string $kind,
        DateTimeImmutable $day,
        string $serverId,
        string $domain,
        string $basePathTemplate,
    ): array {
        $candidates = [];

        foreach ([0, 1] as $offset) {
            $fileDate = $day->modify(sprintf('+%d day', $offset));

            $candidates[] = new self(
                kind: $kind,
                path: self::pathFor($kind, $fileDate, $serverId, $domain, $basePathTemplate),
                domain: $domain,
                serverId: $serverId,
                fileDate: $fileDate,
            );
        }

        return $candidates;
    }

    public static function pathFor(
        string $kind,
        DateTimeImmutable $fileDate,
        string $serverId,
        string $domain,
        string $basePathTemplate,
    ): string {
        $directory = rtrim(strtr($basePathTemplate, [
            '{server_id}' => $serverId,
            '{domain}' => $domain,
        ]), '/');

        return sprintf('%s/%s.%s_log_%s.gz', $directory, $domain, $kind, $fileDate->format('Ymd'));
    }

    public function source(): string
    {
        return self::SOURCE[$this->kind];
    }

    public function filename(): string
    {
        return basename($this->path);
    }

    /** First moment this file holds, in the server's own timezone. */
    public function coverageStart(DateTimeZone $timezone): DateTimeImmutable
    {
        return $this->coverageEnd($timezone)->modify('-1 day');
    }

    /** Last moment this file holds, in the server's own timezone. */
    public function coverageEnd(DateTimeZone $timezone): DateTimeImmutable
    {
        return $this->fileDate
            ->setTimezone($timezone)
            ->setTime(self::COVERAGE_HOUR[$this->kind], 0, 0);
    }

    /**
     * What the core is told about this file.
     *
     * The coverage bounds are spelled out because `target_date` alone would be
     * read as "midnight to midnight", which is exactly what this file is not.
     *
     * @return array<string, mixed>
     */
    public function metadata(DateTimeZone $timezone): array
    {
        return [
            'provider' => 'xserver',
            'domain' => $this->domain,
            'server_identifier' => $this->serverId,
            'xserver_file_date' => $this->fileDate->format('Y-m-d'),
            'xserver_log_kind' => $this->kind,
            'coverage_start_local' => $this->coverageStart($timezone)->format('Y-m-d H:i:s'),
            'coverage_end_local' => $this->coverageEnd($timezone)->format('Y-m-d H:i:s'),
            'timezone' => $timezone->getName(),
            'log_format' => $this->kind === self::ACCESS ? 'xserver_vhost_combined' : 'apache_error',
        ];
    }
}
