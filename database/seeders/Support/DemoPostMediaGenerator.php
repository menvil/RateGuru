<?php

namespace Database\Seeders\Support;

use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DemoPostMediaGenerator
{
    private const WIDTH = 800;

    private const HEIGHT = 600;

    /** @var list<array{from: string, to: string, accent: string}> */
    private const PALETTES = [
        ['from' => '#312e81', 'to' => '#7c3aed', 'accent' => '#c4b5fd'],
        ['from' => '#064e3b', 'to' => '#059669', 'accent' => '#a7f3d0'],
        ['from' => '#7c2d12', 'to' => '#ea580c', 'accent' => '#fed7aa'],
        ['from' => '#0c4a6e', 'to' => '#0284c7', 'accent' => '#bae6fd'],
        ['from' => '#701a75', 'to' => '#c026d3', 'accent' => '#f5d0fe'],
    ];

    public function create(string $path, int $index): MediaAsset
    {
        $palette = self::PALETTES[$index % count(self::PALETTES)];
        $label = strtoupper(str_replace(['-', '_'], ' ', pathinfo($path, PATHINFO_FILENAME)));
        $svg = $this->svg($label, $palette);

        if (! Storage::disk('public')->put($path, $svg)) {
            throw new RuntimeException("Unable to create demo media at [{$path}].");
        }

        return MediaAsset::create([
            'kind' => MediaKind::PostImage,
            'disk' => 'public',
            'path' => $path,
            'original_filename' => basename($path),
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'byte_size' => strlen($svg),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'aspect_ratio' => round(self::WIDTH / self::HEIGHT, 6),
            'orientation' => ImageOrientation::Landscape,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }

    /** @param array{from: string, to: string, accent: string} $palette */
    private function svg(string $label, array $palette): string
    {
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
            <defs>
                <linearGradient id="background" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="{$palette['from']}"/>
                    <stop offset="1" stop-color="{$palette['to']}"/>
                </linearGradient>
            </defs>
            <rect width="800" height="600" fill="url(#background)"/>
            <circle cx="690" cy="90" r="210" fill="{$palette['accent']}" opacity="0.16"/>
            <circle cx="110" cy="560" r="250" fill="{$palette['accent']}" opacity="0.10"/>
            <path d="M0 430 L220 250 L390 390 L585 185 L800 370 L800 600 L0 600 Z" fill="{$palette['accent']}" opacity="0.18"/>
            <text x="54" y="76" fill="{$palette['accent']}" font-family="system-ui, sans-serif" font-size="20" font-weight="700" letter-spacing="4">RATEGURU DEMO</text>
            <text x="54" y="530" fill="#ffffff" font-family="system-ui, sans-serif" font-size="34" font-weight="800">{$label}</text>
        </svg>
        SVG;
    }
}
