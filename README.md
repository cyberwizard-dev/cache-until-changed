# Cache Until Changed

Cache an expensive Laravel result **until the rows behind it change**, with no
model events, observers or cache tags to maintain.

Before serving a cached value, one aggregate query reads each watched table's
row count and latest `updated_at`. Those figures go into the cache key, so:

- an **insert** or **delete** changes a count,
- an **update** or **soft delete** moves `updated_at`,

and the next read recomputes. Writes made with the query builder, raw SQL,
imports or another app sharing the database are all picked up, because nothing
depends on Eloquent events firing.

```php
use Cyberwizard\CacheUntilChanged\Facades\CacheUntilChanged;

$report = CacheUntilChanged::watch('attendance', 'leave', 'shifts')
    ->scope('business_id', $businessId)
    ->key('monthly-report', $businessId, $month, $year, $filters)
    ->remember(fn () => $this->buildReport($businessId, $month, $year, $filters));
```

## Installation

```bash
composer require cyberwizard/cache-until-changed
php artisan vendor:publish --tag=cache-until-changed-config   # optional
```

## Usage

### Watching tables

```php
CacheUntilChanged::watch('attendance', 'leave')            // uses config('cache-until-changed.column')
CacheUntilChanged::watch(['events' => 'modified_at'])      // a table with its own column
```

`scope()` adds `where($column, $value)` to every table passed to `watch()`.

### Tables that need a join

When a table does not carry the scope column, give it its own query. The
closure receives a query on that table and the scope value:

```php
CacheUntilChanged::watchQuery('users', fn ($query, $businessId) => $query
        ->join('business_user', 'business_user.user_id', '=', 'users.id')
        ->where('business_user.business_id', $businessId))
    ->watch('attendance', 'business_user')
    ->scope('business_id', $businessId)
    ->key('employees', $filters)
    ->remember(fn () => ...);
```

The column for a query source is qualified with its table (`users.updated_at`).

### Keys

`key($name, ...$parts)` names the result. Every part (filters, page, user,
today's date for figures that run to today) is part of the key, so each
combination is cached separately.

### Options

| Method | Purpose |
| --- | --- |
| `ttl($seconds\|DateInterval\|DateTime)` | Upper bound on how long a result is kept. Results are replaced as soon as rows change; this only reclaims space. |
| `store('redis')` | Cache store to use. |
| `connection('mysql')` | Database connection for the fingerprint query. |
| `grace(1)` | Writes this recent bypass the cache (see below). |
| `withoutOverlapping(10)` | One request computes a missing result while concurrent ones wait, instead of all computing it. Needs a store with locks. |
| `fingerprint()` | The `Fingerprint` (hash, per-table figures, latest change), for ETags or your own checks. |

### The same-second write

`MAX(updated_at)` has one-second resolution on most columns. A write later in
the same second as the previous one would not change it, so a result cached in
that second could miss it. Any write within `grace_seconds` of now therefore
bypasses the cache, and the result is not stored.

`null` and `false` results are cached like any other value.

## Laravel Octane

The package is built to run under Octane without leaking state between
requests:

- The `CacheUntilChanged` singleton has no properties. Every call starts a
  new builder.
- Builders are immutable. Each method returns a copy, so a builder kept in a
  property or a static and reused across requests is never changed by a later
  request's `scope()` or `key()`.
- The cache, database and config are resolved from the current container when
  the result is read, never captured at boot. Each request uses its own
  sandbox's instances, including config changes made during that request.

Avoid keeping per-request values (such as a computed cache key) on
controllers, which Octane reuses across requests.

## How much it costs

One `UNION ALL` query, one aggregate per watched table, using your indexes on
the scope column. Index `(scope_column, updated_at)` on large tables to keep it
cheap.

Old entries are never read again once their fingerprint changes, and expire
after the TTL.

## Testing

```bash
composer test
```

## License

MIT
