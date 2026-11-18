<?php

namespace App\Support\Traffic\Capture;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * What a page knows about how its visitor arrived, handed to the browser and
 * back with the first call its script makes — the paid visit the click
 * recorded, or the channel and origin an unpaid arrival was found to have,
 * with the referrer's host, the page's route and path, its campaign tags and
 * whether it is a game's result page — and the post a post's own page shows:
 * the call that hands it back is made from elsewhere. Encrypted and tied to the browser's hash, and good for two
 * hours, so a token cannot be made up, replayed from another browser or kept.
 *
 * Tags never make a token longer than MAX_LENGTH, which is all that is read
 * back: tags long enough to swell it past that are cut to the one length
 * that still fits, so the longest lose characters first.
 */
final class ArrivalToken
{
    public const int MAX_LENGTH = 2048;

    private const int LIFETIME = 7200;

    /**
     * The most a token's contents may take: encrypted as config/app.php sets
     * it (AES-256-CBC, base64 twice over), 1 055 bytes come to 2 048 characters.
     */
    private const int MAX_CONTENTS = 1055;

    /**
     * @param  array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool, route?: string|null, path?: string, host?: string|null, post?: int, tags?: array<string, string>}  $arrival
     */
    public static function make(array $arrival, string $browserHash, int $now): string
    {
        $contents = [...$arrival, 'browser' => $browserHash, 'at' => $now];

        if (isset($contents['tags'])) {
            $contents['tags'] = self::fit($contents, $contents['tags']);

            if ($contents['tags'] === []) {
                unset($contents['tags']);
            }
        }

        return Crypt::encryptString(self::encode($contents));
    }

    /** @return array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool, route?: string|null, path?: string, host?: string|null, post?: int, tags?: array<string, string>}|null */
    public static function read(?string $token, string $browserHash, int $now): ?array
    {
        if ($token === null || $token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['browser'] ?? null) !== $browserHash || ! is_int($data['at'] ?? null) || $now - $data['at'] > self::LIFETIME || $data['at'] > $now + 60) {
            return null;
        }

        unset($data['browser'], $data['at']);

        return $data;
    }

    /**
     * The tags, each cut to the most characters that let the contents fit —
     * found by halving, since fewer characters never take more room — and
     * one cut to nothing left out.
     *
     * @param  array<string, mixed>  $contents
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function fit(array $contents, array $tags): array
    {
        $fits = fn (int $most): bool => strlen(self::encode([...$contents, 'tags' => self::cut($tags, $most)])) <= self::MAX_CONTENTS;
        $low = 0;
        $high = $tags === [] ? 0 : max(array_map(mb_strlen(...), $tags));

        if ($fits($high)) {
            return $tags;
        }

        while ($low < $high) {
            $most = intdiv($low + $high + 1, 2);
            [$low, $high] = $fits($most) ? [$most, $high] : [$low, $most - 1];
        }

        return self::cut($tags, $low);
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string> the tags cut to at most the given characters, none left empty
     */
    private static function cut(array $tags, int $most): array
    {
        return array_filter(array_map(fn (string $tag): string => mb_substr($tag, 0, $most), $tags), fn (string $tag): bool => $tag !== '');
    }

    /** @param  array<string, mixed>  $contents */
    private static function encode(array $contents): string
    {
        return json_encode($contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
