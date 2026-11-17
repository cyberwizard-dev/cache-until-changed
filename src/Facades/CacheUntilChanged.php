<?php

namespace Cyberwizard\CacheUntilChanged\Facades;

use Cyberwizard\CacheUntilChanged\PendingCache;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingCache watch(string|array ...$tables)
 * @method static PendingCache watchQuery(string $table, \Closure $query, ?string $column = null)
 *
 * @see \Cyberwizard\CacheUntilChanged\CacheUntilChanged
 */
class CacheUntilChanged extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Cyberwizard\CacheUntilChanged\CacheUntilChanged::class;
    }
}
