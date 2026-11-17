<?php

namespace Cyberwizard\CacheUntilChanged;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * One cached result and the tables it depends on.
 *
 * Immutable: every method returns a copy, so a builder kept in a property or
 * static (and so shared across Octane requests) is never changed by a later
 * call. The cache, database and config are resolved from the current
 * container when the result is read, never captured, so an Octane worker
 * always uses the request's own instances.
 */
final class PendingCache
{
    private const HIT = 'v';

    /**
     * @param  array<string, array{column: ?string, query: ?Closure}>  $sources
     * @param  array{0: string, 1: mixed}|null  $scope
     * @param  array{0: string, 1: list<mixed>}|null  $key
     */
    public function __construct(
        private readonly array $sources = [],
        private readonly ?array $scope = null,
        private readonly ?array $key = null,
        private readonly DateTimeInterface|DateInterval|int|null $ttl = null,
        private readonly ?string $store = null,
        private readonly ?string $connection = null,
        private readonly ?int $graceSeconds = null,
        private readonly ?int $lockSeconds = null,
    ) {}

    /**
     * Tables whose rows the result depends on. Pass 'table' to use the
     * configured column, or ['table' => 'changed_at'] to name one.
     *
     * @param  string|array<int|string, string>  ...$tables
     */
    public function watch(string|array ...$tables): self
    {
        $sources = $this->sources;

        foreach ($tables as $table) {
            foreach ((array) $table as $name => $column) {
                is_int($name)
                    ? $sources[$column] = ['column' => null, 'query' => null]
                    : $sources[$name] = ['column' => $column, 'query' => null];
            }
        }

        return $this->with(sources: $sources);
    }

    /**
     * A table that needs its own query, such as a join to reach the scope.
     * The closure receives a query on $table and the scope value, and may
     * return a new query. The scope is not applied to it automatically.
     */
    public function watchQuery(string $table, Closure $query, ?string $column = null): self
    {
        return $this->with(sources: [...$this->sources, $table => ['column' => $column, 'query' => $query]]);
    }

    /**
     * Limits every table passed to watch() to rows where $column = $value.
     */
    public function scope(string $column, mixed $value): self
    {
        return $this->with(scope: [$column, $value]);
    }

    /**
     * Names the result. Everything in $parts (filters, page, user, day) goes
     * into the key, so each combination is cached on its own.
     */
    public function key(string $name, mixed ...$parts): self
    {
        return $this->with(key: [$name, array_values($parts)]);
    }

    public function ttl(DateTimeInterface|DateInterval|int $ttl): self
    {
        return $this->with(ttl: $ttl);
    }

    public function store(?string $store): self
    {
        return $this->with(store: $store);
    }

    public function connection(?string $connection): self
    {
        return $this->with(connection: $connection);
    }

    public function grace(int $seconds): self
    {
        return $this->with(graceSeconds: max(0, $seconds));
    }

    /**
     * Lets one request compute a missing result while the others wait for
     * it, instead of all computing it at once. Needs a store with locks.
     */
    public function withoutOverlapping(int $seconds = 10): self
    {
        return $this->with(lockSeconds: max(1, $seconds));
    }

    public function fingerprint(): Fingerprint
    {
        if ($this->sources === []) {
            throw new LogicException('Watch at least one table before reading a fingerprint.');
        }

        $db = $this->db();
        $union = null;
        $index = 0;

        foreach ($this->sources as $table => $source) {
            $query = $db->table($table);

            if ($source['query'] !== null) {
                $query = ($source['query'])($query, $this->scope[1] ?? null) ?? $query;
            } elseif ($this->scope !== null) {
                $query->where($this->scope[0], $this->scope[1]);
            }

            $column = $source['column'] ?? ($source['query'] !== null ? $table.'.' : '').$this->config('column', 'updated_at');
            $query->select($db->raw($index.' as s, COUNT(*) as n, MAX('.$query->getGrammar()->wrap($column).') as m'));

            $union === null ? $union = $query : $union->unionAll($query);
            $index++;
        }

        $rows = collect($union->get())
            ->sortBy(fn (object $row): int => (int) $row->s)
            ->map(fn (object $row): array => ['n' => $row->n, 'm' => $row->m])
            ->values()
            ->all();

        return Fingerprint::fromRows($rows);
    }

    /**
     * The cached result while none of the watched rows changed since it was
     * stored; otherwise $callback's result, which is then cached.
     */
    public function remember(Closure $callback): mixed
    {
        if ($this->key === null) {
            throw new LogicException('Name the result with key() before remember().');
        }

        $fingerprint = $this->fingerprint();

        if ($fingerprint->changedWithin($this->graceSeconds ?? (int) $this->config('grace_seconds', 1), Date::now())) {
            return $callback();
        }

        $cache = $this->cache();
        $key = $this->cacheKey($fingerprint);

        if (($hit = $this->read($cache, $key)) !== null) {
            return $hit[self::HIT];
        }

        if ($this->lockSeconds === null || ! $cache->getStore() instanceof LockProvider) {
            return $this->compute($cache, $key, $callback);
        }

        try {
            return $cache->getStore()->lock($key.':lock', $this->lockSeconds)->block(
                $this->lockSeconds,
                fn (): mixed => ($hit = $this->read($cache, $key)) !== null ? $hit[self::HIT] : $this->compute($cache, $key, $callback),
            );
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    public function cacheKey(?Fingerprint $fingerprint = null): string
    {
        if ($this->key === null) {
            throw new LogicException('Name the result with key() first.');
        }

        return implode(':', [
            $this->config('prefix', 'cache-until-changed'),
            $this->key[0],
            md5(serialize($this->key[1])),
            ($fingerprint ?? $this->fingerprint())->hash,
        ]);
    }

    /**
     * @return array{v: mixed}|null
     */
    private function read(Repository $cache, string $key): ?array
    {
        $stored = $cache->get($key);

        return is_array($stored) && array_key_exists(self::HIT, $stored) ? $stored : null;
    }

    private function compute(Repository $cache, string $key, Closure $callback): mixed
    {
        $value = $callback();
        $cache->put($key, [self::HIT => $value], $this->ttl ?? (int) $this->config('ttl', 86400));

        return $value;
    }

    private function cache(): Repository
    {
        return Container::getInstance()->make('cache')->store($this->store ?? $this->config('store'));
    }

    private function db(): ConnectionInterface
    {
        return Container::getInstance()->make('db')->connection($this->connection ?? $this->config('connection'));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return Container::getInstance()->make('config')->get('cache-until-changed.'.$key, $default);
    }

    private function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
