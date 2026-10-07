<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/* The server's own clock, as the UTC string the log stores (microseconds, trailing Z). It is
   clock_timestamp(), not now(): now() is frozen at the start of the transaction, so every
   reading inside one entry would be the same instant. A device's `at` is only ever its claim;
   this is what an auditor checks it against. */
final class ServerClock
{
    public static function now(): string
    {
        return (string) DB::scalar(<<<'SQL'
            select to_char(clock_timestamp() at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
            SQL);
    }
}
