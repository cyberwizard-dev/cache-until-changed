<?php

namespace Cyberwizard\CacheUntilChanged\Tests;

use Cyberwizard\CacheUntilChanged\Facades\CacheUntilChanged;
use Cyberwizard\CacheUntilChanged\PendingCache;
use Illuminate\Support\Facades\DB;
use LogicException;

class RememberTest extends TestCase
{
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 08:00:00');
        $this->visit(1);
        $this->travel(5)->seconds();
    }

    private function visit(int $team): int
    {
        return DB::table('visits')->insertGetId(['team_id' => $team, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cached(int $team = 1): PendingCache
    {
        return CacheUntilChanged::watch('visits')->scope('team_id', $team)->key('visits', $team);
    }

    private function read(?PendingCache $pending = null): int
    {
        return ($pending ?? $this->cached())->remember(function (): int {
            $this->calls++;

            return DB::table('visits')->where('team_id', 1)->count();
        });
    }

    public function test_it_serves_the_cached_result_while_nothing_changed(): void
    {
        $this->assertSame(1, $this->read());
        $this->assertSame(1, $this->read());
        $this->assertSame(1, $this->calls);
    }

    public function test_an_insert_recomputes(): void
    {
        $this->read();
        $this->visit(1);
        $this->travel(5)->seconds();

        $this->assertSame(2, $this->read());
        $this->assertSame(2, $this->calls);
    }

    public function test_a_delete_recomputes(): void
    {
        $id = $this->visit(1);
        $this->travel(5)->seconds();
        $this->read();

        DB::table('visits')->delete($id);

        $this->assertSame(1, $this->read());
        $this->assertSame(2, $this->calls);
    }

    public function test_an_update_recomputes(): void
    {
        $this->read();
        DB::table('visits')->update(['updated_at' => now()]);
        $this->travel(5)->seconds();

        $this->read();
        $this->assertSame(2, $this->calls);
    }

    public function test_changes_outside_the_scope_keep_the_cache(): void
    {
        $this->read();
        $this->visit(2);
        $this->travel(5)->seconds();

        $this->read();
        $this->assertSame(1, $this->calls);
    }

    public function test_a_write_inside_the_grace_window_is_never_cached(): void
    {
        $this->visit(1);

        $this->read();
        $this->read();
        $this->assertSame(2, $this->calls, 'a second write in this same second would not move MAX(updated_at)');

        $this->travel(2)->seconds();
        $this->read();
        $this->read();
        $this->assertSame(3, $this->calls);
    }

    public function test_key_parts_are_cached_apart(): void
    {
        $base = CacheUntilChanged::watch('visits')->scope('team_id', 1);

        $this->read($base->key('visits', ['page' => 1]));
        $this->read($base->key('visits', ['page' => 2]));
        $this->read($base->key('visits', ['page' => 1]));

        $this->assertSame(2, $this->calls);
    }

    public function test_null_and_false_are_cached_too(): void
    {
        foreach ([null, false] as $value) {
            $calls = 0;
            $pending = $this->cached()->key('value', var_export($value, true));

            $pending->remember(function () use (&$calls, $value) {
                $calls++;

                return $value;
            });
            $this->assertSame($value, $pending->remember(function () use (&$calls, $value) {
                $calls++;

                return $value;
            }));
            $this->assertSame(1, $calls);
        }
    }

    public function test_a_table_can_name_its_own_column(): void
    {
        $pending = CacheUntilChanged::watch(['visits' => 'changed_at'])->scope('team_id', 1)->key('visits');
        $this->read($pending);

        DB::table('visits')->update(['updated_at' => now()->addMinute()]);
        $this->read($pending);
        $this->assertSame(1, $this->calls, 'updated_at is not watched');

        DB::table('visits')->update(['changed_at' => now()->subMinute()]);
        $this->read($pending);
        $this->assertSame(2, $this->calls);
    }

    public function test_a_query_source_reaches_its_scope_through_a_join(): void
    {
        $person = DB::table('people')->insertGetId(['name' => 'Ada', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['team_id' => 1, 'person_id' => $person, 'created_at' => now(), 'updated_at' => now()]);
        $this->travel(5)->seconds();

        $pending = CacheUntilChanged::watchQuery('people', fn ($query, $team) => $query
            ->join('memberships', 'memberships.person_id', '=', 'people.id')
            ->where('memberships.team_id', $team))
            ->watch('visits')
            ->scope('team_id', 1)
            ->key('people');

        $this->read($pending);
        DB::table('people')->insert(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        $this->read($pending);
        $this->assertSame(1, $this->calls, 'a person outside the team');

        DB::table('people')->where('id', $person)->update(['name' => 'Ada L', 'updated_at' => now()]);
        $this->travel(5)->seconds();
        $this->read($pending);
        $this->assertSame(2, $this->calls);
    }

    public function test_without_overlapping_still_computes_once(): void
    {
        $pending = $this->cached()->withoutOverlapping(5);

        $this->read($pending);
        $this->read($pending);

        $this->assertSame(1, $this->calls);
    }

    public function test_it_needs_a_key_and_a_table(): void
    {
        $this->expectException(LogicException::class);

        CacheUntilChanged::watch('visits')->remember(fn () => 1);
    }

    public function test_it_needs_a_table_for_a_fingerprint(): void
    {
        $this->expectException(LogicException::class);

        (new PendingCache)->fingerprint();
    }
}
