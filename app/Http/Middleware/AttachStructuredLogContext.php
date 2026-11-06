<?php

namespace App\Http\Middleware;

use App\Support\Observability\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AttachStructuredLogContext
{
    public function __construct(private readonly LogContext $logContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('observability.structured_context.enabled', true)) {
            Log::withContext($this->logContext->base());
        }

        return $next($request);
    }
}
