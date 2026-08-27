<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        app()->instance('request_id', $requestId);

        // The same ID, published on Laravel's own correlation channel. The
        // container binding above dies with the request; Context is dehydrated
        // into a queued job's payload and rehydrated by the worker, so a job
        // dispatched from this request can be traced back to it — and it is
        // what Nightwatch reads natively. Written once, here: this middleware
        // is where a request ID comes from, and nothing else invents one.
        Context::add('request_id', $requestId);

        $request->headers->set(config('observability.request_id.header', 'X-Request-Id'), $requestId);

        $response = $next($request);

        if (config('observability.request_id.response_header', true)) {
            $response->headers->set(config('observability.request_id.header', 'X-Request-Id'), $requestId);
        }

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $header = config('observability.request_id.header', 'X-Request-Id');
        $incoming = $request->header($header);

        if ($incoming !== null && $this->isValid($incoming)) {
            return $incoming;
        }

        return (string) Str::uuid();
    }

    private function isValid(string $id): bool
    {
        return strlen($id) >= 1
            && strlen($id) <= 128
            && preg_match('/^[A-Za-z0-9\-]+$/', $id) === 1;
    }
}
