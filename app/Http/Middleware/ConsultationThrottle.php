<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ConsultationThrottle
{
    public function handle(Request $request, Closure $next, int $maximum = 30): mixed
    {
        // Explicit file store and IP/endpoint key: never resolves the operator user or SQL cache.
        $limiter = new RateLimiter(Cache::store('file'));
        $endpoint = $request->route()?->uri() ?? $request->path();
        $key = 'findward-consultation:'.hash('sha256', $request->method().'|'.$endpoint.'|'.$request->ip());
        if ($limiter->tooManyAttempts($key, $maximum)) {
            return response()->json(['code' => 'rate_limited', 'message' => 'Please wait a minute before trying again.'], 429)
                ->header('Retry-After', (string) $limiter->availableIn($key))->header('Cache-Control', 'private, no-store');
        }
        $limiter->hit($key, 60);

        return $next($request);
    }
}
