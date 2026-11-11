<?php

namespace App\Support\Games\Snapshot;

use App\Enums\GameOptionSource;

/**
 * A round option as published: its picture's post or upload, its label, and
 * — for a post — the site votes it had at that moment.
 */
final readonly class SnapshotOption
{
    public function __construct(
        public int $position,
        public GameOptionSource $source,
        public ?int $postId,
        public ?int $assetId,
        public LocalizedText $label,
        public int $upvotes,
        public int $downvotes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            position: (int) $data['position'],
            source: GameOptionSource::from((string) $data['source']),
            postId: isset($data['post_id']) ? (int) $data['post_id'] : null,
            assetId: isset($data['asset_id']) ? (int) $data['asset_id'] : null,
            label: LocalizedText::of($data['label'] ?? []),
            upvotes: (int) ($data['upvotes'] ?? 0),
            downvotes: (int) ($data['downvotes'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'source' => $this->source->value,
            'post_id' => $this->postId,
            'asset_id' => $this->assetId,
            'label' => $this->label->toArray(),
            'upvotes' => $this->upvotes,
            'downvotes' => $this->downvotes,
        ];
    }

    /** The frozen upvote share as a percentage, rounded for display; null for an upload. */
    public function displayShare(): ?float
    {
        $votes = $this->upvotes + $this->downvotes;

        return $this->source === GameOptionSource::Upload || $votes === 0 ? null : round($this->upvotes * 100 / $votes, 1);
    }
}
