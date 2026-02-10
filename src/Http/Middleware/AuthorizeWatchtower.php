<?php

namespace Documateai\Watchtower\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeWatchtower
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $gate = config('watchtower.gate', 'viewWatchtower');

        if (Gate::denies($gate)) {
            abort(403, 'Unauthorized access to Watchtower.');
        }

        return $next($request);
    }
}
