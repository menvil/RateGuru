<?php

namespace App\Support\Traffic\Capture;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * What a page knows about how its visitor arrived, handed to the browser and
 * back with the first call its script makes — the paid visit the click
 * recorded, or the channel and origin an unpaid arrival was found to have.
 * Encrypted and tied to the browser's hash, and good for two hours, so a
 * token cannot be made up, replayed from another browser or kept.
 */
final class ArrivalToken
{
    private const int LIFETIME = 7200;

    /**
     * @param  array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool}  $arrival
     */
    public static function make(array $arrival, string $browserHash, int $now): string
    {
        return Crypt::encryptString(json_encode([...$arrival, 'browser' => $browserHash, 'at' => $now], JSON_THROW_ON_ERROR));
    }

    /** @return array{visit?: int, channel?: string, origin?: string|null, slug?: string|null, result?: bool}|null */
    public static function read(?string $token, string $browserHash, int $now): ?array
    {
        if ($token === null || $token === '' || strlen($token) > 2048) {
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
}
