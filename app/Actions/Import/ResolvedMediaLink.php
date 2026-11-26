<?php

namespace App\Actions\Import;

/**
 * What a link given to the upload form comes to: a picture to fetch now, or
 * a video to download once the post exists — never both — and the post's
 * source when the link was a post on a site. With it, what the form shows
 * before posting: a still of it, and a video's length when the site says.
 */
final readonly class ResolvedMediaLink
{
    public function __construct(
        public ?string $imageUrl,
        public ?string $videoUrl,
        public ?string $source,
        public ?string $previewImageUrl = null,
        public ?int $durationSeconds = null,
    ) {}

    public function isVideo(): bool
    {
        return $this->videoUrl !== null;
    }

    /** @return array{imageUrl: ?string, videoUrl: ?string, source: ?string, previewImageUrl: ?string, durationSeconds: ?int} */
    public function toArray(): array
    {
        return [
            'imageUrl' => $this->imageUrl,
            'videoUrl' => $this->videoUrl,
            'source' => $this->source,
            'previewImageUrl' => $this->previewImageUrl,
            'durationSeconds' => $this->durationSeconds,
        ];
    }

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        $string = fn (string $key): ?string => is_string($values[$key] ?? null) ? $values[$key] : null;

        return new self(
            $string('imageUrl'),
            $string('videoUrl'),
            $string('source'),
            $string('previewImageUrl'),
            is_int($values['durationSeconds'] ?? null) ? $values['durationSeconds'] : null,
        );
    }
}
