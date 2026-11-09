<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | Presence lives in the cache, never in the database: who is on a record,
    | which field they are in, and who saved it last. Any store that supports
    | atomic locks works (redis, database, memcached, dynamodb, file). Null
    | uses the default store. Every server must share the store, so the file
    | driver only fits a single machine, and the array store only a test: it
    | forgets everything when the request ends.
    |
    */

    'cache_store' => env('FILAMENT_COEDIT_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat and timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Each open page reports in every `heartbeat` seconds (and at once when its
    | user moves to another field). Someone who stops reporting for `timeout`
    | seconds — closed the tab, lost the network — drops off the record. The
    | timeout is never shorter than two heartbeats plus a second.
    |
    */

    'heartbeat' => 4,

    'timeout' => 20,

    /*
    |--------------------------------------------------------------------------
    | Idle (seconds)
    |--------------------------------------------------------------------------
    |
    | A page nobody has touched for this long stops reporting in, and its
    | person drops off the record until they type, click or move the pointer.
    | Every heartbeat is a request that keeps the session alive, so without
    | this a page left open would never sign its user out. Null never pauses.
    |
    */

    'idle' => 600,

    /*
    |--------------------------------------------------------------------------
    | Last save memory (seconds)
    |--------------------------------------------------------------------------
    |
    | How long "Ana saved this record" is remembered for the people still on
    | it. Only used to name who saved and what changed; the merge itself
    | always compares against the database.
    |
    */

    'remember_saves_for' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Field presence
    |--------------------------------------------------------------------------
    |
    | Show which field each person is in. Off, the avatars stay and the
    | browser never reports focus; the merge on save is not affected.
    |
    */

    'track_fields' => true,

    /*
    |--------------------------------------------------------------------------
    | Leave route middleware
    |--------------------------------------------------------------------------
    |
    | A closing tab tells the server it left through a small signed request to
    | `filament-coedit/leave`, outside your panel's middleware. If presence
    | lives in a cache your tenancy scopes per tenant, add the middleware that
    | identifies the tenant here (stancl/tenancy: InitializeTenancyByDomain or
    | InitializeTenancyBySubdomain); without it the person drops off after
    | `timeout` instead of at once.
    |
    */

    'leave_middleware' => [],

];
