<?php

namespace Fleetbase\FleetOps\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Makes a route answer in JSON whatever the client's Accept header says.
 *
 * Laravel decides how to answer a failed validation from `expectsJson()`: a
 * request that does not ask for JSON is redirected back to the page it came
 * from with its errors flashed to a session. The public inspection page has
 * no session and cannot follow that redirect, and the platform's fetch
 * service sends `Accept: *\/*`, so a refused submission reached the page as a
 * bare 302 with its reasons lost.
 */
class ForceJsonResponse
{
    public function handle(Request $request, \Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
