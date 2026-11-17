<?php

namespace Cyberwizard\CacheUntilChanged;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Row count and latest change of every watched table, reduced to a hash.
 */
final class Fingerprint
{
    /**
     * @param  list<array{n: int, m: ?string}>  $tables
     */
    public function __construct(
        public readonly array $tables,
        public readonly string $hash,
        public readonly ?CarbonImmutable $latestChange,
    ) {}

    /**
     * @param  list<array{n: int|string, m: mixed}>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $tables = [];
        $latest = null;

        foreach ($rows as $row) {
            $changed = $row['m'] instanceof DateTimeInterface
                ? CarbonImmutable::instance($row['m'])
                : ($row['m'] === null || $row['m'] === '' ? null : CarbonImmutable::parse((string) $row['m']));

            $tables[] = ['n' => (int) $row['n'], 'm' => $changed?->format('Y-m-d H:i:s.u')];

            if ($changed !== null && ($latest === null || $changed->greaterThan($latest))) {
                $latest = $changed;
            }
        }

        return new self($tables, md5(json_encode($tables)), $latest);
    }

    public function changedWithin(int $seconds, DateTimeInterface $now): bool
    {
        if ($this->latestChange === null || $seconds <= 0) {
            return false;
        }

        return $this->latestChange->greaterThanOrEqualTo(CarbonImmutable::instance($now)->subSeconds($seconds));
    }
}
