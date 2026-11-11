<?php

namespace App\Support\Games\Snapshot;

use App\Enums\GameRoundMode;

/**
 * A round as published. `answer` is the option that earns the point — the
 * crowd's pick as the votes stood at publication, or the correct option —
 * and null for a round that plays without points.
 */
final readonly class SnapshotRound
{
    /** @param  list<SnapshotOption>  $options */
    public function __construct(
        public string $key,
        public LocalizedText $question,
        public LocalizedText $description,
        public LocalizedText $reveal,
        public GameRoundMode $mode,
        public bool $scored,
        public bool $showCrowd,
        public ?int $answer,
        public array $options,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            question: LocalizedText::of($data['question'] ?? []),
            description: LocalizedText::of($data['description'] ?? []),
            reveal: LocalizedText::of($data['reveal'] ?? []),
            mode: GameRoundMode::from((string) $data['mode']),
            scored: (bool) $data['scored'],
            showCrowd: (bool) ($data['show_crowd'] ?? true),
            answer: isset($data['answer']) ? (int) $data['answer'] : null,
            options: array_values(array_map(fn (array $option): SnapshotOption => SnapshotOption::fromArray($option), (array) $data['options'])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'question' => $this->question->toArray(),
            'description' => $this->description->toArray(),
            'reveal' => $this->reveal->toArray(),
            'mode' => $this->mode->value,
            'scored' => $this->scored,
            'show_crowd' => $this->showCrowd,
            'answer' => $this->answer,
            'options' => array_map(fn (SnapshotOption $option): array => $option->toArray(), $this->options),
        ];
    }

    public function option(int $position): ?SnapshotOption
    {
        foreach ($this->options as $option) {
            if ($option->position === $position) {
                return $option;
            }
        }

        return null;
    }
}
