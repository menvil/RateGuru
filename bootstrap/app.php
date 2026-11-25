<?php

use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\AttachStructuredLogContext;
use App\Http\Middleware\AttachVisitorLogContext;
use App\Http\Middleware\CaptureTrafficArrival;
use App\Http\Middleware\EnsureAccountIsNotTombstoned;
use App\Http\Middleware\EnsureSessionGenerationIsCurrent;
use App\Http\Middleware\RecordAccountReturn;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackMemberVisit;
use App\Support\Auth\TurnstileVerifier;
use App\Support\Observability\ExceptionContextBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
            // SetLocale leads, because the guards after it end sessions and
            // redirect with a message. Translating that message needs the
            // language already resolved: a session being invalidated takes the
            // session-stored choice with it, but the `locale` cookie and
            // Accept-Language survive, so resolving first is the difference
            // between the visitor's language and the project default.
            SetLocale::class,
            EnsureAccountIsNotTombstoned::class,
            EnsureSessionGenerationIsCurrent::class,
            // Last, so the user and the locale it adds to the log context
            // are the ones the guards above have settled.
            AttachVisitorLogContext::class,
            // How the visitor arrived: a paid click is recorded here, before
            // any route's own middleware can send the visitor elsewhere
            // (docs/architecture/traffic.md).
            CaptureTrafficArrival::class,
            // A new account coming back on another day is worth more for it.
            RecordAccountReturn::class,
            // When a member was last seen: where their previous visit ended,
            // which the feed counts fresh posts in their language from.
            TrackMemberVisit::class,
        ]);
        // Ahead of route model binding, which runs earlier in the group: a post
        // that is not there is found missing while the bindings resolve, and its
        // not-found page is drawn in whatever language is set by then.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetLocale::class);
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

        // Single-use, and of no use to the form drawn after a failure.
        $exceptions->dontFlash([TurnstileVerifier::FIELD]);

        $exceptions->context(function (Throwable $e) {
            return app(ExceptionContextBuilder::class)->build($e);
        });

        // An old confirmation link is an everyday thing, not an error page: it
        // lands where a new one can be asked for.
        $exceptions->render(fn (InvalidSignatureException $e, Request $request) => $request->routeIs('verification.verify')
            ? redirect()->route('verification.notice')->with('status', 'verification-link-expired')
            : null);
    })->create();
