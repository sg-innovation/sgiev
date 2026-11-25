<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API: a blocked account's tokens stop working at once. */
class EnsureApiUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->blocked_at) {
            $request->user()->currentAccessToken()?->delete();
            abort(403, 'This account is blocked. Contact support.');
        }

        return $next($request);
    }
}
