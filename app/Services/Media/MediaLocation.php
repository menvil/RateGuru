<?php

namespace App\Services\Media;

use InvalidArgumentException;

/**
 * The only identity a stored file has: a Laravel filesystem disk name plus
 * the path inside that disk. Never a URL — resolving one is
 * MediaUrlResolver's job, not something this value carries.
 */
final readonly class MediaLocation
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {
        if (trim($disk) === '') {
            throw new InvalidArgumentException('Media disk cannot be empty.');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException('Media path cannot be empty.');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException("Media path cannot be a URL: [{$path}].");
        }

        if (str_starts_with($path, '/storage/') || $path === '/storage') {
            throw new InvalidArgumentException("Media path cannot be a public URL path: [{$path}].");
        }
    }
}
