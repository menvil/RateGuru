<?php

namespace App\Services\Images;

final class ImageCleanup
{
    public function delete(?string $path, string $disk = 'public'): void
    {
        if ($path === null || $path === '') {
            return;
        }

        // Real deletion can be enabled later.
    }
}
