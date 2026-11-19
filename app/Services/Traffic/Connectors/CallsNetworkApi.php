<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficConnectorFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How a connector talks to its network: JSON, RateGuru's own user agent, no
 * redirects followed, 5 seconds to connect and 20 in all, no retries of its
 * own; and a failure turned into its reason alone — the client's exception,
 * which names the address, never leaves here.
 */
trait CallsNetworkApi
{
    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('RateGuru traffic sync')
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws ConnectorException
     */
    private function call(callable $call): Response
    {
        try {
            $response = $call($this->client());
        } catch (ConnectionException $exception) {
            throw new ConnectorException(str_contains(strtolower($exception->getMessage()), 'timed out') ? TrafficConnectorFailure::Timeout : TrafficConnectorFailure::Unavailable);
        } catch (Throwable) {
            throw new ConnectorException(TrafficConnectorFailure::Unavailable);
        }

        if ($response->successful()) {
            return $response;
        }

        throw new ConnectorException(match (true) {
            in_array($response->status(), [401, 403], true) => TrafficConnectorFailure::Auth,
            $response->status() === 429 => TrafficConnectorFailure::RateLimited,
            $response->serverError() => TrafficConnectorFailure::Unavailable,
            default => TrafficConnectorFailure::Rejected,
        }, $response->status());
    }

    /**
     * @return array<mixed>
     *
     * @throws ConnectorException
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse, $response->status());
        }

        return $json;
    }
}
