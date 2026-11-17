<?php

namespace Cyberwizard\CacheUntilChanged\Tests;

use Cyberwizard\CacheUntilChanged\CacheUntilChangedServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CacheUntilChangedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('person_id');
            $table->timestamps();
        });

        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
    }
}
