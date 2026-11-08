<?php

namespace App\Http\Middleware;

use App\Support\Observability\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares what is known about the request with every log line it writes, from
 * the first middleware on. The visitor half — user, locale, theme — is added
 * later by AttachVisitorLogContext, once the session can answer for it.
 */
final class AttachStructuredLogContext
{
    public function __construct(private readonly LogContext $logContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('observability.structured_context.enabled', true)) {
            Log::withContext($this->logContext->request());
        }

        return $next($request);
    }
}
