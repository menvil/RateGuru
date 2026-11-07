<?php

namespace App\Http\Middleware;

use App\Support\Observability\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds who is asking, and in which language, to every log line the rest of
 * the request writes. It runs after the session has started, the locale has
 * been resolved and an outdated session has been ended — before that, the
 * user is not known yet and the locale is still the project default.
 */
final class AttachVisitorLogContext
{
    public function __construct(private readonly LogContext $logContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('observability.structured_context.enabled', true)) {
            Log::withContext($this->logContext->visitor());
        }

        return $next($request);
    }
}
