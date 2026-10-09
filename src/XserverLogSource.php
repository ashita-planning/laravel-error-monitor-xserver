<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitorXserver;

use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use Apkk\LaravelErrorMonitor\DTO\AnalysisWindowData;
use Apkk\LaravelErrorMonitor\DTO\CollectedLogFileData;
use Apkk\LaravelErrorMonitorXserver\Support\XserverLogFile;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Offers the Apache logs XServer stores in the account's own home directory.
 *
 * This first version reads what is already on disk and nothing else. It opens
 * no connection, holds no credential, decompresses nothing and never writes to
 * or removes the files it reads - the originals belong to the hosting account,
 * not to this package.
 *
 * Candidate selection follows the configured file-date convention. The core
 * retains responsibility for filtering entries to the analysis window.
 */
final class XserverLogSource implements ServerLogSource
{
    /** Files that were looked for and were not there. @var array<int, string> */
    private array $missing = [];

    /**
     * @param  string  $id  Identifier this source registers under.
     * @param  string  $serverId  XServer account identifier, e.g. `sv00000`.
     * @param  array<int, string>  $domains  Domains whose logs to offer.
     * @param  string  $basePathTemplate  Directory template with `{server_id}` and `{domain}`.
     * @param  string  $timezone  Timezone the server names and writes its logs in.
     * @param  array<int, string>  $kinds  Which of `access` / `error` to offer.
     * @param  bool  $enabled  A disabled adapter stays registered and offers nothing.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $serverId,
        private readonly array $domains,
        private readonly string $basePathTemplate,
        private readonly string $timezone = 'Asia/Tokyo',
        private readonly array $kinds = [XserverLogFile::ACCESS, XserverLogFile::ERROR],
        private readonly bool $enabled = true,
        private readonly string $fileDateBasis = XserverLogFile::DATE_END,
    ) {
        XserverLogFile::validateFileDateBasis($fileDateBasis);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return iterable<CollectedLogFileData> */
    public function collect(?AnalysisWindowData $window = null): iterable
    {
        $this->missing = [];

        // Nothing configured is not a failure: the package can be installed on
        // a host that is not XServer, and simply has nothing to offer there.
        if (! $this->enabled || $this->serverId === '' || $this->domains === [] || $this->kinds === []) {
            return [];
        }

        $timezone = new DateTimeZone($this->timezone);
        $collected = [];

        foreach ($this->days($window, $timezone) as $day) {
            foreach ($this->domains as $domain) {
                foreach ($this->kinds as $kind) {
                    foreach (XserverLogFile::candidatesFor($kind, $day, $this->serverId, $domain, $this->basePathTemplate, $this->fileDateBasis) as $candidate) {
                        $file = $this->offer($candidate, $timezone);

                        if ($file instanceof CollectedLogFileData) {
                            $collected[$candidate->path] = $file;
                        }
                    }
                }
            }
        }

        return array_values($collected);
    }

    /**
     * Files that were expected and absent.
     *
     * Absence is normal rather than exceptional: XServer writes the logs around
     * 06:00, so today's file does not exist yet, and the manual states outright
     * that the error log is not generated at all while disk usage is above 80%.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * Local days the window touches.
     *
     * @return array<int, DateTimeImmutable>
     */
    private function days(?AnalysisWindowData $window, DateTimeZone $timezone): array
    {
        if ($window === null) {
            // No period in mind: collect the files covering yesterday.
            return [(new DateTimeImmutable('now', $timezone))->modify('-1 day')->setTime(0, 0, 0)];
        }

        $day = DateTimeImmutable::createFromInterface($window->from)->setTimezone($timezone)->setTime(0, 0, 0);
        $last = DateTimeImmutable::createFromInterface($window->to)->setTimezone($timezone)->setTime(0, 0, 0);
        $days = [];

        while ($day <= $last) {
            $days[$day->format('Y-m-d')] = $day;
            $day = $day->modify('+1 day');
        }

        return array_values($days);
    }

    private function offer(XserverLogFile $file, DateTimeZone $timezone): ?CollectedLogFileData
    {
        if (! is_file($file->path) || ! is_readable($file->path)) {
            $this->missing[] = $file->path;

            return null;
        }

        $hash = CollectedLogFileData::hashOf($file->path);

        if ($hash === '') {
            $this->missing[] = $file->path;

            return null;
        }

        return new CollectedLogFileData(
            source: $file->source(),
            path: $file->path,
            targetDate: $file->fileDate,
            fileHash: $hash,
            // Handed over still compressed: the core streams `.gz` itself, so
            // decompressing here would only duplicate that work and put a
            // plaintext copy of somebody's access log on disk.
            compressed: true,
            metadata: $file->metadata($timezone),
        );
    }
}
