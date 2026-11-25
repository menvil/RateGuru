<?php

namespace App\Support\Languages;

/**
 * What the local detector told of a text when it was written: its language, if any, and whether the text has
 * words at all — one of only emoji, names and links is not worth asking OpenAI about.
 */
final readonly class TextLanguage
{
    public function __construct(
        public ?DetectedLanguage $detected,
        public bool $hasWords,
    ) {}

    public function language(): ?string
    {
        return $this->detected?->language;
    }

    public function weight(): float
    {
        return $this->detected === null ? 0.0 : $this->detected->weight;
    }
}
