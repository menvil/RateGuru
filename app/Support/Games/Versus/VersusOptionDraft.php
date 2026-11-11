<?php

namespace App\Support\Games\Versus;

use App\Enums\GameOptionSource;

/**
 * A round option as it stands in a draft, with what the site knows about its
 * post and picture right now. Built by the caller from the database; the
 * checks themselves read nothing.
 */
final readonly class VersusOptionDraft
{
    public function __construct(
        public GameOptionSource $source,
        public ?int $postId = null,
        public int $upvotes = 0,
        public int $downvotes = 0,
        /** The post is published and not deleted. */
        public bool $postPublic = false,
        public ?int $assetId = null,
        public ImageState $image = ImageState::Unavailable,
        public bool $isCorrect = false,
        public ?string $label = null,
    ) {}

    public function crowdSide(): CrowdSide
    {
        return new CrowdSide(
            postId: $this->source === GameOptionSource::Post ? $this->postId : null,
            upvotes: $this->upvotes,
            downvotes: $this->downvotes,
            eligible: $this->postPublic && $this->image === ImageState::Ready,
        );
    }
}
