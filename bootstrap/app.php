<?php

use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\AttachStructuredLogContext;
use App\Http\Middleware\EnsureAccountIsNotTombstoned;
use App\Http\Middleware\SetLocale;
use App\Support\Observability\ExceptionContextBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            AttachRequestId::class,
            AttachStructuredLogContext::class,
        ]);
        $middleware->web(append: [
            EnsureAccountIsNotTombstoned::class,
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The single Sentry capture path for the whole application. It hangs
        // off Laravel's `reportable`, which only runs for exceptions Laravel
        // already decided are worth reporting — so the framework's dontReport
        // list (404, validation, authentication, authorization, CSRF, rate
        // limiting) keeps ordinary user mistakes out of Sentry, and unhandled
        // 5xx failures from HTTP, Artisan and queue workers all arrive here
        // exactly once. Nothing else in the application calls captureException.
        Integration::handles($exceptions);

        $exceptions->context(function (Throwable $e) {
            return app(ExceptionContextBuilder::class)->build($e);
        });
    })->create();
