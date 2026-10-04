<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use Illuminate\Support\Str;

/**
 * Builds the object key a file is stored at. Never derived from the
 * client-supplied filename (which is untrusted and not collision-resistant)
 * — always a fresh UUID, except for the explicit deterministic path demo
 * seeders opt into so reruns land on the same key.
 */
final class MediaPathGenerator
{
    private const MAX_EXTENSION_LENGTH = 10;

    public function generate(MediaStoreRequest $request, ?string $extension): string
    {
        if ($request->explicitPath !== null) {
            return $request->explicitPath;
        }

        $filename = Str::uuid()->toString();
        $safeExtension = $this->safeExtension($extension);

        if ($safeExtension !== null) {
            $filename .= ".{$safeExtension}";
        }

        $directory = rtrim($request->directory, '/');

        return match ($request->kind) {
            // One instant, not two now() calls — otherwise a rollover
            // between the year and month reads could split a path across
            // two different months (e.g. .../2026/12/... then .../2027/01/...
            // for the same file).
            MediaKind::PostImage => "{$directory}/".now()->format('Y/m')."/{$filename}",
            MediaKind::Avatar => "{$directory}/{$request->ownerUserId}/{$filename}",
        };
    }

    private function safeExtension(?string $extension): ?string
    {
        if ($extension === null || $extension === '') {
            return null;
        }

        $extension = strtolower($extension);

        if (mb_strlen($extension) > self::MAX_EXTENSION_LENGTH) {
            return null;
        }

        return preg_match('/^[a-z0-9]+$/', $extension) === 1 ? $extension : null;
    }
}
