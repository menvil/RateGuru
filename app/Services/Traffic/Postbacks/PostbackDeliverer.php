<?php

namespace App\Services\Traffic\Postbacks;

use App\Enums\TrafficPostbackOutcome;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\OutboundUrlPolicy;
use App\Support\Import\ResolvedImportTarget;
use App\Support\Import\UrlImportValidator;
use App\Support\Traffic\Postbacks\PostbackResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends postbacks to the addresses administrators typed, as safely as an
 * import fetches a page (docs/architecture/traffic.md, "Postbacks"): http or
 * https on 80 or 443, a host whose DNS answers are all public, checked once
 * per host for the run, the connection pinned to the IP checked, no
 * redirects followed, no proxy, an answer read to 16 KB at most, 3 seconds
 * to connect and 8 in all. Up to eight at a time. Never inside a database
 * transaction; the address never reaches a log.
 */
final class PostbackDeliverer
{
    public const int CONCURRENCY = 8;

    public const int MAX_BYTES = 16384;

    /** @var array<string, string|false> the IP checked for each host, false when refused */
    private array $checked = [];

    public function __construct(private readonly UrlImportValidator $validator) {}

    /**
     * @param  array<int|string, array{url: string, rules: array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null}>  $postbacks  by key
     * @return array<int|string, PostbackAttempt> by the same key
     */
    public function send(array $postbacks): array
    {
        $results = [];
        $targets = [];

        foreach ($postbacks as $key => $postback) {
            $target = $this->target($postback['url']);

            if ($target === null) {
                $results[$key] = new PostbackAttempt(TrafficPostbackOutcome::BlockedUrl, null, null, null);
            } else {
                $targets[$key] = $target;
            }
        }

        foreach (array_chunk($targets, self::CONCURRENCY, true) as $chunk) {
            $started = hrtime(true);
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (int|string $key): mixed => $pool->as((string) $key)->withOptions($this->options($chunk[$key]))
                    ->connectTimeout(3)->timeout(8)->withoutRedirecting()->get($chunk[$key]->url),
                array_keys($chunk),
            ));
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);

            foreach (array_keys($chunk) as $key) {
                $response = $responses[(string) $key] ?? null;
                // Each answer's own time where the transfer gives it; the chunk's only for a request that never got one.
                $own = $response instanceof Response ? (float) ($response->handlerStats()['total_time'] ?? 0) : 0.0;
                $results[$key] = $this->attempt($response, $postbacks[$key]['rules'], $own > 0 ? (int) round($own * 1000) : $latency);
            }
        }

        return $results;
    }

    private function target(string $url): ?ResolvedImportTarget
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (($this->checked[$host] ?? null) === false) {
            return null;
        }

        try {
            $target = $this->validator->validateFor($url, OutboundUrlPolicy::postback());
        } catch (UnsafeImportUrlException) {
            $this->checked[$host] = false;

            return null;
        }

        $this->checked[$host] = $target->ip;

        return $target;
    }

    /** @return array<string, mixed> */
    private function options(ResolvedImportTarget $target): array
    {
        return [
            'decode_content' => false,
            // No ambient proxy: a proxy resolves the host itself, and the pin below would not hold.
            'proxy' => '',
            // An answer past the cap stops being read: true makes curl abort, and what came so far is the answer.
            'progress' => fn (int $total, int $downloaded): bool => $downloaded > self::MAX_BYTES,
            'curl' => [
                CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$target->ip}"],
            ],
        ];
    }

    /** @param  array{rules: list<array{outcome: string, match: string, text: string}>, otherwise: string}|null  $rules */
    private function attempt(mixed $response, ?array $rules, int $latency): PostbackAttempt
    {
        if ($response instanceof Response) {
            // An answer whose status and headers came but whose body never finished arriving still comes back as a
            // response: it timed out all the same, and is tried again like any other timeout.
            if ($response->transferStats?->getHandlerErrorData() === CURLE_OPERATION_TIMEDOUT) {
                return new PostbackAttempt(TrafficPostbackOutcome::Timeout, null, $latency, null);
            }

            $body = mb_strcut($response->body(), 0, self::MAX_BYTES);

            $headers = [];

            foreach ($response->headers() as $name => $values) {
                $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }

            // A header a rule looks at is what the network said: kept in the log with the body.
            $said = collect($rules['rules'] ?? [])->where('match', 'header')
                ->map(fn (array $rule): string => trim($rule['text']))
                ->filter(fn (string $name): bool => trim($headers[strtolower($name)] ?? '') !== '')
                ->map(fn (string $name): string => $name.': '.$headers[strtolower($name)].' ')
                ->implode('');

            return new PostbackAttempt(PostbackResponse::outcome($response->status(), $body, $rules, $headers), $response->status(), $latency, PostbackResponse::excerpt($body, $said));
        }

        if ($response instanceof ConnectionException && str_contains(strtolower($response->getMessage()), 'timed out')) {
            return new PostbackAttempt(TrafficPostbackOutcome::Timeout, null, $latency, null);
        }

        return new PostbackAttempt(TrafficPostbackOutcome::ConnectionError, null, $latency, null);
    }
}
