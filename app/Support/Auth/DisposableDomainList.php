<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\DisposableDomains;
use ReflectionClass;

/**
 * The list of throwaway mail domains, kept so that it cannot stop sign-ups.
 *
 * The package writes over the live file and then fails on anything it cannot
 * decode, its own update command included. A sign-up read during an update,
 * or a write cut short, would end in an error page until somebody removed the
 * file. Here a new list appears whole or not at all, and a stored list that
 * cannot be read gives way to the one the package ships with.
 */
final class DisposableDomainList extends DisposableDomains
{
    /** @return array<array-key, string> */
    protected function getFromStorage(): array
    {
        $shipped = dirname((string) (new ReflectionClass(DisposableDomains::class))->getFileName(), 2).'/domains.json';

        return array_diff(
            $this->read((string) $this->getStoragePath()) ?? $this->read($shipped) ?? [],
            $this->getWhitelist(),
        );
    }

    /**
     * @param  array<array-key, mixed>  $domains
     * @return int|false the bytes written, or false with the old list still in place
     */
    public function saveToStorage(array $domains): int|false
    {
        $encoded = $this->usable($domains) === null ? false : json_encode($domains);

        if ($encoded === false) {
            return false;
        }

        $path = (string) $this->getStoragePath();
        $temporary = $path.'.'.Str::random(12);

        if (@file_put_contents($temporary, $encoded) !== strlen($encoded) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        $this->flushCache();

        return strlen($encoded);
    }

    /** @return array<array-key, string>|null */
    private function read(string $path): ?array
    {
        $content = is_file($path) ? @file_get_contents($path) : false;

        return is_string($content) ? $this->usable(json_decode($content, true)) : null;
    }

    /** @return array<array-key, string>|null */
    private function usable(mixed $domains): ?array
    {
        return is_array($domains) && $domains !== [] && array_filter($domains, is_string(...)) === $domains
            ? $domains
            : null;
    }
}
