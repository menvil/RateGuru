<?php

use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\AttachStructuredLogContext;
use App\Http\Middleware\SetLocale;
use App\Support\Observability\ExceptionContextBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(function (Throwable $e) {
            return app(ExceptionContextBuilder::class)->build($e);
        });
    })->create();
