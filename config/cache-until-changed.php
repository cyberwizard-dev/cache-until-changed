<?php

return [

    /*
    | Cache store for results. Null uses the default store.
    */
    'store' => env('CACHE_UNTIL_CHANGED_STORE'),

    /*
    | Database connection the fingerprint query runs on. Null uses the default.
    */
    'connection' => null,

    /*
    | Upper bound on how long a result is kept, in seconds. A result is
    | replaced as soon as its rows change; this only reclaims space.
    */
    'ttl' => 86400,

    /*
    | Column read from every watched table unless the table names its own.
    */
    'column' => 'updated_at',

    /*
    | A write this many seconds old or newer bypasses the cache. A second
    | write within the same second leaves MAX(updated_at) unchanged, so a
    | result cached in that window could miss it.
    */
    'grace_seconds' => 1,

    'prefix' => 'cache-until-changed',

];
