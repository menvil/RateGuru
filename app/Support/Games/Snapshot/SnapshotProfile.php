<?php

namespace App\Support\Games\Snapshot;

/** A result profile as published, with the matches it stands for. */
final readonly class SnapshotProfile
{
    public function __construct(
        public string $key,
        public LocalizedText $name,
        public LocalizedText $description,
        public int $min,
        public int $max,
        public string $icon,
        public LocalizedText $shareText,
        public ?int $shareAssetId,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            name: LocalizedText::of($data['name'] ?? []),
            description: LocalizedText::of($data['description'] ?? []),
            min: (int) $data['min'],
            max: (int) $data['max'],
            icon: (string) $data['icon'],
            shareText: LocalizedText::of($data['share_text'] ?? []),
            shareAssetId: isset($data['share_asset_id']) ? (int) $data['share_asset_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name->toArray(),
            'description' => $this->description->toArray(),
            'min' => $this->min,
            'max' => $this->max,
            'icon' => $this->icon,
            'share_text' => $this->shareText->toArray(),
            'share_asset_id' => $this->shareAssetId,
        ];
    }
}
