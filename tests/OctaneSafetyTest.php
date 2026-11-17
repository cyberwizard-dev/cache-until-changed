<?php

namespace Cyberwizard\CacheUntilChanged\Tests;

use Cyberwizard\CacheUntilChanged\CacheUntilChanged as Manager;
use Cyberwizard\CacheUntilChanged\Facades\CacheUntilChanged;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * Octane keeps one application alive across requests and gives each request
 * a clone of it (the sandbox). These tests stand in for that.
 */
class OctaneSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 08:00:00');
        DB::table('visits')->insert(['team_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->travel(5)->seconds();
    }

    public function test_the_singleton_holds_no_state(): void
    {
        $properties = (new ReflectionClass(Manager::class))->getProperties();

        $this->assertSame([], $properties);
        $this->assertSame(app(Manager::class), app(Manager::class));
    }

    public function test_a_builder_kept_between_requests_is_never_changed(): void
    {
        $shared = CacheUntilChanged::watch('visits')->key('visits');

        $first = $shared->scope('team_id', 1);
        $second = $shared->scope('team_id', 2);

        $this->assertNotSame($first->cacheKey(), $second->cacheKey());
        $this->assertSame($first->cacheKey(), $shared->scope('team_id', 1)->cacheKey());
    }

    public function test_one_request_never_sees_another_requests_scope(): void
    {
        $shared = CacheUntilChanged::watch('visits')->key('count');

        $teamOne = $shared->scope('team_id', 1)->remember(fn () => 'team 1');
        $teamTwo = $shared->scope('team_id', 2)->remember(fn () => 'team 2');

        $this->assertSame(['team 1', 'team 2'], [$teamOne, $teamTwo]);
    }

    public function test_services_come_from_the_current_request_container(): void
    {
        $pending = CacheUntilChanged::watch('visits')->scope('team_id', 1)->key('visits');
        $pending->remember(fn () => 'base');

        $base = Container::getInstance();
        $sandbox = clone $base;
        $sandbox->instance('config', clone $base->make('config'));
        $sandbox->make('config')->set('cache-until-changed.prefix', 'sandbox');
        Container::setInstance($sandbox);

        try {
            $this->assertStringStartsWith('sandbox:', $pending->cacheKey());
            $this->assertSame('sandbox', $pending->remember(fn () => 'sandbox'));
        } finally {
            Container::setInstance($base);
        }

        $this->assertSame('base', $pending->remember(fn () => 'not reached'));
    }

    public function test_a_store_change_between_requests_is_picked_up(): void
    {
        config(['cache.stores.second' => ['driver' => 'array']]);
        $pending = CacheUntilChanged::watch('visits')->scope('team_id', 1)->key('visits');

        $pending->remember(fn () => 'default store');
        config(['cache-until-changed.store' => 'second']);

        $this->assertSame('second store', $pending->remember(fn () => 'second store'));
        $this->assertNotNull(cache()->store('second')->get($pending->cacheKey()));
    }
}
