<?php

namespace App\Support\Import;

class ImportProviderDetector
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'];

    private const SOCIAL_HOST_PATTERNS = [
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.watch'],
        'x' => ['x.com', 'twitter.com'],
        'pinterest' => ['pinterest.com', 'pin.it', 'pinterest.co.uk'],
    ];

    public function detect(string $url): string
    {
        $parsed = parse_url(strtolower($url));
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        foreach (self::SOCIAL_HOST_PATTERNS as $provider => $patterns) {
            foreach ($patterns as $pattern) {
                if ($host === $pattern || str_ends_with($host, '.'.$pattern)) {
                    return $provider;
                }
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'direct_image';
        }

        return 'open_graph';
    }
}
