<?php

namespace App\Services\Media;

use App\Enums\MediaVariantName;

/**
 * One encoding of a video: the master (at most 1080 on the short side, which
 * also serves as the full-size picture) or a display rendition below it.
 * Architectural constants, like the image variant specifications.
 */
final readonly class VideoRendition
{
    public function __construct(
        public ?MediaVariantName $name,
        public int $shortSide,
        public int $crf,
        public int $maxrateKbps,
        public int $audioKbps,
    ) {}

    public static function master(): self
    {
        return new self(null, 1080, 23, 5000, 128);
    }

    /**
     * Made only below the master's own short side: a 720p upload gets a 480
     * rendition and no 720 one.
     *
     * @return list<self>
     */
    public static function displays(): array
    {
        return [
            new self(MediaVariantName::Video720, 720, 23, 2500, 128),
            new self(MediaVariantName::Video480, 480, 24, 1200, 96),
        ];
    }

    /**
     * The output size for a picture of $width x $height: scaled so its short
     * side is at most this rendition's, never up, and both sides even — the
     * H.264 encoder takes nothing else. Rounding down keeps a source of odd
     * size from growing by a pixel.
     *
     * @return array{int, int}
     */
    public function dimensionsFor(int $width, int $height): array
    {
        $short = min($width, $height);
        $scale = $short > $this->shortSide ? $this->shortSide / $short : 1.0;
        $even = fn (float $value): int => max(2, (int) (floor($value / 2) * 2));

        return [$even($width * $scale), $even($height * $scale)];
    }
}
