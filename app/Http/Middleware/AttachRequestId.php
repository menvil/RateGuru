<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        app()->instance('request_id', $requestId);

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
