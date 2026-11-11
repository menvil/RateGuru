<?php

namespace App\Support\Games\Snapshot;

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Support\Games\Flow\FlowStepDraft;

/** A flow step as published: its settings and its texts in every language it had. */
final readonly class SnapshotStep
{
    /**
     * @param  array<string, bool|int|float|string|null>  $settings
     * @param  array<string, LocalizedText>  $texts
     */
    public function __construct(
        public string $key,
        public GameFlowStepType $type,
        public GameStepAudience $audience,
        public array $settings,
        public array $texts,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $texts = [];

        foreach ((array) ($data['texts'] ?? []) as $column => $values) {
            $texts[(string) $column] = LocalizedText::of($values);
        }

        return new self(
            key: (string) $data['key'],
            type: GameFlowStepType::from((string) $data['type']),
            audience: GameStepAudience::from((string) $data['audience']),
            settings: (array) ($data['settings'] ?? []),
            texts: $texts,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'audience' => $this->audience->value,
            'settings' => $this->settings,
            'texts' => array_map(fn (LocalizedText $text): array => $text->toArray(), $this->texts),
        ];
    }

    public function text(string $column, string $locale): string
    {
        return ($this->texts[$column] ?? LocalizedText::of([]))->in($locale);
    }

    public function setting(string $key): bool|int|float|string|null
    {
        return $this->settings[$key] ?? $this->type->defaultSettings()[$key] ?? null;
    }

    /** The step as the flow rules read it, with its English texts. */
    public function draft(): FlowStepDraft
    {
        return new FlowStepDraft(
            key: $this->key,
            type: $this->type,
            audience: $this->audience,
            settings: $this->settings,
            texts: array_map(fn (LocalizedText $text): string => $text->english(), $this->texts),
        );
    }
}
