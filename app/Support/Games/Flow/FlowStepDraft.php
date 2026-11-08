<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;

/**
 * A flow step as an editor or a snapshot holds it: its type, audience,
 * settings and English texts by column. Its key is stable across saves, so a
 * player standing on it keeps standing on it.
 */
final readonly class FlowStepDraft
{
    /**
     * @param  array<string, bool|int|float|string|null>  $settings
     * @param  array<string, string|null>  $texts
     */
    public function __construct(
        public string $key,
        public GameFlowStepType $type,
        public GameStepAudience $audience = GameStepAudience::All,
        public array $settings = [],
        public array $texts = [],
    ) {}

    public function setting(string $key): bool|int|float|string|null
    {
        return $this->settings[$key] ?? $this->type->defaultSettings()[$key] ?? null;
    }

    public function text(string $column): string
    {
        return trim((string) ($this->texts[$column] ?? ''));
    }

    public function signup(): GameSignupMode
    {
        return GameSignupMode::tryFrom((string) $this->setting('signup')) ?? GameSignupMode::Inline;
    }

    /**
     * The labels of the English texts the step needs and lacks. Some are
     * needed only by how the step is set: a skip link only while the email
     * is optional, a consent label only while the checkbox is shown.
     *
     * @return list<string>
     */
    public function missingTexts(): array
    {
        $missing = [];

        foreach ($this->type->texts() as $column => $text) {
            $required = $text['required']
                || ($this->type === GameFlowStepType::EmailCapture && $column === 'secondary_label' && ! $this->setting('required'))
                || ($this->type === GameFlowStepType::EmailCapture && $column === 'consent_label' && (bool) $this->setting('consent_enabled'));

            if ($required && $this->text($column) === '') {
                $missing[] = $text['label'];
            }
        }

        return $missing;
    }
}
