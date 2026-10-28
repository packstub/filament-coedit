<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

/** Stands in for a tenancy middleware on the leave route. */
class CountsLeaveRequests
{
    public static int $count = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        static::$count++;

        return $next($request);
    }
}
