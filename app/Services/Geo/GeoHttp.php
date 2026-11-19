<?php

namespace App\Services\Geo;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP calls of the IP database update: fixed vendor hosts only, no
 * redirects followed, explicit timeouts, no automatic retries (the next
 * source is the retry), and downloads streamed to a file and cut off past a
 * size limit.
 */
final class GeoHttp
{
    public function json(string $url, string $source): array
    {
        $response = $this->send(fn (): Response => $this->request((int) config('geo.timeouts.metadata'))->acceptJson()->get($url), $source, GeoUpdateFailure::DiscoveryFailed);

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $source);
        }

        return $data;
    }

    public function head(string $url, string $source): Response
    {
        return $this->send(fn (): Response => $this->request((int) config('geo.timeouts.metadata'))->head($url), $source, GeoUpdateFailure::DiscoveryFailed);
    }

    /** Streams the body to $path; refuses anything but 200 and anything over $maxBytes. */
    public function download(string $url, string $path, int $maxBytes, string $source): void
    {
        $tooLarge = false;

        // An explicit closure, not an arrow function: an arrow function would
        // capture $tooLarge by value, and the guards below would set its copy
        // instead of the flag send() reads.
        $response = $this->send(function () use ($url, $path, $maxBytes, &$tooLarge): Response {
            return $this->request((int) config('geo.timeouts.download'))->withOptions([
                'sink' => $path,
                'decode_content' => false,
                'on_headers' => function ($response) use ($maxBytes, &$tooLarge): void {
                    $length = $response->getHeaderLine('Content-Length');

                    if ($length !== '' && (int) $length > $maxBytes) {
                        $tooLarge = true;

                        throw new \RuntimeException('The download is larger than the limit.');
                    }
                },
                'progress' => function ($total, $downloaded) use ($maxBytes, &$tooLarge): void {
                    if ($downloaded > $maxBytes) {
                        $tooLarge = true;

                        throw new \RuntimeException('The download is larger than the limit.');
                    }
                },
            ])->get($url);
        }, $source, GeoUpdateFailure::DownloadFailed, $tooLarge);

        if ($response->status() !== 200) {
            throw GeoUpdateException::because(GeoUpdateFailure::DownloadFailed, $source);
        }

        clearstatcache(true, $path);

        if ((int) @filesize($path) > $maxBytes) {
            throw GeoUpdateException::because(GeoUpdateFailure::TooLarge, $source);
        }
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::connectTimeout((int) config('geo.timeouts.connect'))
            ->timeout($timeout)
            ->withoutRedirecting()
            ->withUserAgent(config('app.name').' IP database update');
    }

    /**
     * A call that does not complete fails as $failure — a metadata request
     * as discovery, the archive as its download — or as too large once a
     * download's size guard has cut it off.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $source, GeoUpdateFailure $failure, bool &$tooLarge = false): Response
    {
        try {
            return $call();
        } catch (Throwable) {
            throw GeoUpdateException::because($tooLarge ? GeoUpdateFailure::TooLarge : $failure, $source);
        }
    }
}
