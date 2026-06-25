<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum first-party SPA (cookie/session) authentication.
        $middleware->statefulApi();

        // Role-based authorization guard + activity tracking for routes.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'track' => \App\Http\Middleware\TrackActivity::class,
            'active' => \App\Http\Middleware\EnsureActive::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'mfa.enrolled' => \App\Http\Middleware\EnsureMfaEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
