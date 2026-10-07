<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        // ConsultationSession supplies file sessions and normal CSRF for every public route.
        $middleware->web(remove: [
            StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
        ], append: [AddLinkHeadersForPreloadedAssets::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('consultation/*') || $request->expectsJson());
    })->create();
