<?php

namespace App\Filament\Resources\Concerns;

use App\Models\RatingGroup;
use App\Models\User;
use App\Support\Translations\ProjectTranslationCatalog;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A rating group's settings as its editor holds them: the English label and
 * description, the range of active options, its place on post pages and
 * whether voters see it. What is typed is a draft until Save in the top bar
 * (NAV-02 — edit pages save from the top bar): the browser compares the
 * draft to `saved` to say whether anything is unsaved, and Discard puts the
 * saved settings back.
 *
 * The range is checked as the server will check it, live, so the steppers
 * say what is wrong before Save is pressed, and Save waits for it
 * (`settingsBlocked`).
 */
trait EditsRatingGroupSettings
{
    /** The largest sort order the editor takes. */
    public const MAX_SORT_ORDER = 999999;

    public string $draftLabel = '';

    public string $draftDescription = '';

    public mixed $draftMin = RatingGroup::FEWEST_OPTIONS;

    public mixed $draftMax = RatingGroup::FEWEST_OPTIONS;

    public mixed $draftSort = 0;

    public bool $draftActive = false;

    /**
     * The settings as saved, keyed as the browser compares them.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $saved = [];

    /** Whether the draft cannot be saved as it is; the top bar's Save waits for it. */
    #[Locked]
    public bool $settingsBlocked = false;

    /** Puts the saved settings back in the draft. */
    #[On('discard-rating-group')]
    public function discard(): void
    {
        $this->fillDraft($this->saved);
        $this->resetErrorBag();
    }

    /** @param  array<string, mixed>  $values */
    protected function fillDraft(array $values): void
    {
        $this->draftLabel = (string) $values['label'];
        $this->draftDescription = (string) $values['description'];
        $this->draftMin = (int) $values['min'];
        $this->draftMax = (int) $values['max'];
        $this->draftSort = (int) $values['sort'];
        $this->draftActive = (bool) $values['active'];
    }

    /** @return array<string, mixed> */
    protected static function savedValuesOf(RatingGroup $group): array
    {
        return [
            'label' => $group->label,
            'description' => (string) $group->description,
            'min' => $group->min_options,
            'max' => $group->max_options,
            'sort' => $group->sort_order,
            'active' => $group->is_active,
        ];
    }

    /**
     * What the draft saves: the English text squished as stored, the numbers
     * as numbers.
     *
     * @return array<string, mixed>
     */
    protected function draftData(): array
    {
        $description = trim($this->draftDescription);

        return [
            'label' => Str::squish($this->draftLabel),
            'description' => $description === '' ? null : $description,
            'min_options' => (int) $this->draftMin,
            'max_options' => (int) $this->draftMax,
            'sort_order' => (int) $this->draftSort,
            'is_active' => $this->draftActive,
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function settingsRules(): array
    {
        return [
            'draftLabel' => ['required', 'string', 'max:'.self::labelLimit()],
            'draftDescription' => ['nullable', 'string', 'max:'.self::descriptionLimit()],
            'draftMin' => ['required', 'integer', 'between:'.RatingGroup::FEWEST_OPTIONS.','.RatingGroup::MOST_OPTIONS, 'lte:draftMax'],
            'draftMax' => ['required', 'integer', 'between:'.RatingGroup::FEWEST_OPTIONS.','.RatingGroup::MOST_OPTIONS],
            'draftSort' => ['required', 'integer', 'between:0,'.self::MAX_SORT_ORDER],
            'draftActive' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function settingsMessages(): array
    {
        $range = 'Enter a whole number from '.RatingGroup::FEWEST_OPTIONS.' to '.RatingGroup::MOST_OPTIONS.'.';

        return [
            'draftLabel.required' => 'Enter a label.',
            'draftLabel.max' => 'Keep the label to '.self::labelLimit().' characters or fewer.',
            'draftDescription.max' => 'Keep the description to '.number_format(self::descriptionLimit()).' characters or fewer.',
            'draftMin.lte' => 'Minimum can’t be higher than the maximum.',
            'draftMin.*' => $range,
            'draftMax.*' => $range,
            'draftSort.*' => 'Enter a whole number from 0 to '.number_format(self::MAX_SORT_ORDER).'.',
        ];
    }

    /**
     * What keeps the range from holding the group's active options while the
     * draft has the group active — the messages Save gives — by field. An
     * inactive group may be outside its range while its options are made.
     *
     * @return array<string, string>
     */
    protected function rangeProblems(int $active): array
    {
        if (! is_numeric($this->draftMin) || ! is_numeric($this->draftMax)) {
            return [];
        }

        $min = (int) $this->draftMin;
        $max = (int) $this->draftMax;
        $are = $active === 1 ? '1 option is' : "{$active} options are";

        return match (true) {
            $min > $max => ['draftMin' => 'Minimum can’t be higher than the maximum.'],
            ! $this->draftActive => [],
            $active < $min => ['draftMin' => "{$are} active, fewer than this minimum."],
            $active > $max => ['draftMax' => "{$are} active. Deactivate or archive ".($active - $max).' first.'],
            default => [],
        };
    }

    /** The English label is held to what its translations are held to. */
    protected static function labelLimit(): int
    {
        return ProjectTranslationCatalog::FIELDS['rating_groups']['label'][0];
    }

    protected static function descriptionLimit(): int
    {
        return ProjectTranslationCatalog::FIELDS['rating_groups']['description'][0];
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array{label: string, event: string, params: array<string, mixed>}|null  $action
     */
    protected function toast(string $message, string $tone = 'success', ?array $action = null): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }
}
