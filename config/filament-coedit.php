<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | Presence lives in the cache, never in the database: who is on a record,
    | which field they are in, and who saved it last. Any store that supports
    | atomic locks works (redis, database, memcached, dynamodb, file, array).
    | Null uses the default store. Every server must share the store, so the
    | file driver only fits a single machine.
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

];
