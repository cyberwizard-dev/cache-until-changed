<?php

namespace Cyberwizard\CacheUntilChanged;

use Closure;

/**
 * Entry point. Holds no state: every call starts a new PendingCache, so it is
 * safe to keep as a singleton under Octane.
 */
final class CacheUntilChanged
{
    /**
     * @param  string|array<int|string, string>  ...$tables
     */
    public function watch(string|array ...$tables): PendingCache
    {
        return (new PendingCache)->watch(...$tables);
    }

    public function watchQuery(string $table, Closure $query, ?string $column = null): PendingCache
    {
        return (new PendingCache)->watchQuery($table, $query, $column);
    }
}
