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
 * File dates can identify either the start or end of a rotation period.
 * The default preserves the historical end-date convention and boundaries;
 * operators must verify the convention against their hosting environment.
 */
final readonly class XserverLogFile
{
    public const ACCESS = 'access';

    public const ERROR = 'error';

    public const DATE_START = 'start';

    public const DATE_END = 'end';

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
        public string $fileDateBasis = self::DATE_END,
    ) {
        self::validateFileDateBasis($fileDateBasis);

        if (! array_key_exists($kind, self::COVERAGE_HOUR)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a known XServer log kind.', $kind));
        }
    }

    /**
     * Files that together hold every entry of the given local day.
     *
     * End-date files use D and D+1; start-date files use D-1 and D.
     * Each pair overlaps the target day at the kind's rotation boundary.
     *
     * @return array<int, self>
     */
    public static function candidatesFor(
        string $kind,
        DateTimeImmutable $day,
        string $serverId,
        string $domain,
        string $basePathTemplate,
        string $fileDateBasis = self::DATE_END,
    ): array {
        self::validateFileDateBasis($fileDateBasis);
        $candidates = [];

        foreach ($fileDateBasis === self::DATE_START ? [-1, 0] : [0, 1] as $offset) {
            $fileDate = $day->modify(sprintf('%+d day', $offset));

            $candidates[] = new self(
                kind: $kind,
                path: self::pathFor($kind, $fileDate, $serverId, $domain, $basePathTemplate),
                domain: $domain,
                serverId: $serverId,
                fileDate: $fileDate,
                fileDateBasis: $fileDateBasis,
            );
        }

        return $candidates;
    }

    public static function validateFileDateBasis(string $basis): void
    {
        if (! in_array($basis, [self::DATE_START, self::DATE_END], true)) {
            throw new InvalidArgumentException('XServer file_date_basis must be start or end.');
        }
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
        $end = $this->fileDate
            ->setTimezone($timezone)
            ->setTime(self::COVERAGE_HOUR[$this->kind], 0, 0);

        return $this->fileDateBasis === self::DATE_START ? $end->modify('+1 day') : $end;
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
            'xserver_file_date_basis' => $this->fileDateBasis,
            'xserver_log_kind' => $this->kind,
            'coverage_start_local' => $this->coverageStart($timezone)->format('Y-m-d H:i:s'),
            'coverage_end_local' => $this->coverageEnd($timezone)->format('Y-m-d H:i:s'),
            'timezone' => $timezone->getName(),
            'log_format' => $this->kind === self::ACCESS ? 'xserver_vhost_combined' : 'apache_error',
        ];
    }
}
