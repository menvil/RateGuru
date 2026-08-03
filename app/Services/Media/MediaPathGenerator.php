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
            MediaKind::PostImage => "{$directory}/".now()->format('Y').'/'.now()->format('m')."/{$filename}",
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
