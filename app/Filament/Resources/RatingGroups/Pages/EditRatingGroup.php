<?php

namespace App\Filament\Resources\RatingGroups\Pages;

use App\Actions\Rating\ActivateRatingOptionRaisingMaximumAction;
use App\Actions\Rating\ArchiveRatingOptionAction;
use App\Actions\Rating\CreateRatingOptionAction;
use App\Actions\Rating\DeleteRatingOptionAction;
use App\Actions\Rating\UnarchiveRatingOptionAction;
use App\Actions\Rating\UpdateRatingGroupAction;
use App\Actions\Rating\UpdateRatingOptionAction;
use App\Exceptions\Rating\CannotDeleteVotedRatingOptionException;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Filament\Resources\Concerns\EditsRatingGroupSettings;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Support\ContentTranslationStatus;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * One rating group, in Admin v2: its settings, saved from the top bar; the
 * check of its active options against the range the draft sets (STS-04);
 * its translations; and its options, each edited in a drawer and activated,
 * deactivated, archived, unarchived or deleted from its row menu.
 *
 * The options are saved as they change, each through its action under the
 * group's lock, which keeps the active options inside the range as saved:
 * an option that would leave it is explained, with the way out — and for
 * one more than the maximum allows, raising the maximum and activating it in
 * one step. An option with votes is archived, never deleted, so past results
 * stay as they were.
 *
 * @phpstan-type OptionRow array{id: int, label: string, description: string, key: string, active: bool, archived: ?string, sort: int, votes: int}
 */
final class EditRatingGroup extends Page
{
    use EditsRatingGroupSettings;

    protected static string $resource = RatingGroupResource::class;

    protected string $view = 'filament.pages.rating-group';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Locked]
    public int $groupId = 0;

    /** The option the drawer edits. */
    #[Locked]
    public ?int $optionEditing = null;

    #[Locked]
    public bool $optionCreating = false;

    public string $draftOptionLabel = '';

    public string $draftOptionKey = '';

    /** Whether a new option's key still follows its label, as it does until somebody types one. */
    #[Locked]
    public bool $optionKeyGenerated = false;

    public string $draftOptionDescription = '';

    public mixed $draftOptionSort = 0;

    public bool $draftOptionActive = false;

    /** The confirmation on screen: archive, delete, voted, below-min or above-max. */
    #[Locked]
    public ?string $confirming = null;

    /** The option the confirmation on screen is about. */
    #[Locked]
    public ?int $confirmingOption = null;

    /** What a below-min confirmation says the option cannot be: deactivated, archived or deleted. */
    #[Locked]
    public string $blockedAs = 'deactivated';

    private ?RatingGroup $loaded = null;

    public static function canAccess(array $parameters = []): bool
    {
        return RatingGroupResource::canAccess();
    }

    public function mount(int|string $record): void
    {
        $group = is_numeric($record) ? RatingGroup::query()->find((int) $record) : null;
        abort_if($group === null, 404);

        $this->groupId = $group->id;
        $this->saved = self::savedValuesOf($group);
        $this->fillDraft($this->saved);

        // Created a moment ago, on the page that redirected here.
        if (is_string($created = session('rg-admin-toast'))) {
            $this->toast($created);
        }
    }

    public function getTitle(): string
    {
        return $this->group()->label;
    }

    #[On('save-rating-group')]
    public function save(): void
    {
        $group = $this->group();
        $this->draftLabel = Str::squish($this->draftLabel);

        $this->validate($this->settingsRules(), $this->settingsMessages());

        if ($this->refuseRange($group)) {
            return;
        }

        try {
            app(UpdateRatingGroupAction::class)->handle($this->actor(), $group, $this->draftData());
        } catch (InvalidRatingGroupConfigurationException) {
            // An option changed meanwhile: the range no longer holds what it has.
            $this->loaded = null;

            if (! $this->refuseRange($this->group())) {
                $this->toast('The group could not be saved: its options changed meanwhile. Try again.', 'error');
            }

            return;
        }

        $this->loaded = null;
        $this->saved = self::savedValuesOf($this->group());
        $this->fillDraft($this->saved);
        $this->resetErrorBag();
        $this->toast("{$this->group()->label} saved");
    }

    // The option drawer ----------------------------------------------------------

    public function createOption(): void
    {
        $group = $this->group();
        $this->closeConfirmation();
        $this->closeOptionDrawer();
        $this->optionCreating = true;
        $this->optionKeyGenerated = true;
        $this->draftOptionSort = min(self::MAX_SORT_ORDER, (int) $group->options()->max('sort_order') + 1);
        // Active when the group has room for one more, as an option usually is.
        $this->draftOptionActive = $group->hasRoomForActiveOption($this->activeCount());
    }

    public function editOption(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        $this->closeOptionDrawer();
        $this->optionEditing = $option->id;
        $this->draftOptionLabel = $option->label;
        $this->draftOptionKey = $option->key;
        $this->draftOptionDescription = (string) $option->description;
        $this->draftOptionSort = $option->sort_order;
        $this->draftOptionActive = $option->is_active;
    }

    public function updatedDraftOptionLabel(): void
    {
        if ($this->optionCreating && $this->optionKeyGenerated) {
            $this->draftOptionKey = Str::slug($this->draftOptionLabel, '_');
        }
    }

    public function updatedDraftOptionKey(): void
    {
        $this->optionKeyGenerated = false;
    }

    public function closeOptionDrawer(): void
    {
        $this->optionEditing = null;
        $this->optionCreating = false;
        $this->optionKeyGenerated = false;
        $this->draftOptionLabel = '';
        $this->draftOptionKey = '';
        $this->draftOptionDescription = '';
        $this->draftOptionSort = 0;
        $this->draftOptionActive = false;
        $this->resetErrorBag(['draftOptionLabel', 'draftOptionKey', 'draftOptionDescription', 'draftOptionSort', 'draftOptionActive']);
    }

    public function saveOption(): void
    {
        $group = $this->group();
        $option = null;

        if (! $this->optionCreating) {
            $option = $this->findOption($this->optionEditing);

            if ($option === null) {
                $this->closeOptionDrawer();
                $this->refuseMissing();

                return;
            }

            // What the drawer shows as locked stays as it is: the key, and
            // Active while the option cannot change it.
            $this->draftOptionKey = $option->key;

            if ($this->activeLock($option) !== null) {
                $this->draftOptionActive = $option->is_active;
            }
        } elseif ($this->activeLock(null) !== null) {
            $this->draftOptionActive = false;
        }

        $this->draftOptionLabel = Str::squish($this->draftOptionLabel);
        $this->draftOptionKey = trim($this->draftOptionKey);
        $this->draftOptionSort = is_string($this->draftOptionSort) ? trim($this->draftOptionSort) : $this->draftOptionSort;
        [$labelLimit] = ProjectTranslationCatalog::FIELDS['rating_options']['label'];
        [$descriptionLimit] = ProjectTranslationCatalog::FIELDS['rating_options']['description'];

        $this->validate([
            'draftOptionLabel' => ['required', 'string', 'max:'.$labelLimit],
            'draftOptionKey' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/', Rule::unique(RatingOption::class, 'key')->where('rating_group_id', $group->id)->ignore($option?->id)],
            'draftOptionDescription' => ['nullable', 'string', 'max:'.$descriptionLimit],
            'draftOptionSort' => ['required', 'integer', 'between:0,'.self::MAX_SORT_ORDER],
            'draftOptionActive' => ['boolean'],
        ], [
            'draftOptionLabel.required' => 'Enter a label.',
            'draftOptionLabel.max' => "Keep the label to {$labelLimit} characters or fewer.",
            'draftOptionKey.required' => 'Enter a key.',
            'draftOptionKey.max' => 'Keep the key to 120 characters or fewer.',
            'draftOptionKey.regex' => 'Use lowercase letters and numbers, with single underscores between words: heart_melting.',
            'draftOptionKey.unique' => 'This key is already used by another option of this group. Choose another one.',
            'draftOptionDescription.max' => 'Keep the description to '.number_format($descriptionLimit).' characters or fewer.',
            'draftOptionSort.*' => 'Enter a whole number from 0 to '.number_format(self::MAX_SORT_ORDER).'.',
        ]);

        $description = trim($this->draftOptionDescription);
        $data = [
            'label' => $this->draftOptionLabel,
            'description' => $description === '' ? null : $description,
            'sort_order' => (int) $this->draftOptionSort,
            'is_active' => $this->draftOptionActive,
        ];

        try {
            if ($option === null) {
                $created = app(CreateRatingOptionAction::class)->handle($this->actor(), $group, [...$data, 'key' => $this->draftOptionKey]);
                $this->toast("{$created->label} created".($created->is_active ? '' : ' as inactive'));
            } else {
                app(UpdateRatingOptionAction::class)->handle($this->actor(), $option, $data);
                $this->toast("{$data['label']} saved");
            }
        } catch (InvalidRatingGroupConfigurationException) {
            $this->loaded = null;
            $this->addError('draftOptionActive', $this->draftOptionActive ? $this->fullMessage() : $this->minimumMessage());

            return;
        }

        $this->loaded = null;
        $this->closeOptionDrawer();
    }

    // The option's row menu --------------------------------------------------------

    /** Shows an option to voters. From a toast's Undo it says so without offering another. */
    #[On('activate-rating-option')]
    public function activateOption(mixed $id = null, mixed $undo = false): void
    {
        $this->setOptionActive($id, true, $undo === true);
    }

    /** Hides an option from voters; its votes stay in results. */
    #[On('deactivate-rating-option')]
    public function deactivateOption(mixed $id = null, mixed $undo = false): void
    {
        $this->setOptionActive($id, false, $undo === true);
    }

    /** Activates an option of a full group, raising the maximum by the one it needs. */
    public function raiseMaximumAndActivate(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        try {
            $maximum = app(ActivateRatingOptionRaisingMaximumAction::class)->handle($this->actor(), $option);
        } catch (InvalidRatingGroupConfigurationException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The saved maximum moved; the draft follows it.
        $this->saved['max'] = $maximum;
        $this->draftMax = $maximum;
        $this->loaded = null;
        $this->toast("Maximum raised to {$maximum} · {$option->label} activated");
    }

    /** Asks before archiving; says so when the group cannot spare the option. */
    public function askToArchive(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        if ($option->archived_at !== null) {
            $this->toast("{$option->label} is already archived.", 'info');

            return;
        }

        $this->confirm($option, $this->cannotSpare($option) ? 'below-min' : 'archive', 'archived');
    }

    public function archiveOption(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        try {
            app(ArchiveRatingOptionAction::class)->handle($this->actor(), $option);
        } catch (InvalidRatingGroupConfigurationException) {
            $this->confirm($option, 'below-min', 'archived');

            return;
        }

        $this->loaded = null;
        $this->toast("{$option->label} archived");
    }

    /** Takes an option out of the archive; it comes back inactive. */
    public function unarchiveOption(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        app(UnarchiveRatingOptionAction::class)->handle($this->actor(), $option);
        $this->loaded = null;
        $this->toast("{$option->label} unarchived · inactive");
    }

    /** Asks before deleting; an option with votes is offered the archive instead. */
    public function askToDeleteOption(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        $this->confirm($option, match (true) {
            $option->votes()->exists() => 'voted',
            $this->cannotSpare($option) => 'below-min',
            default => 'delete',
        }, 'deleted');
    }

    public function deleteOption(mixed $id = null): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        try {
            app(DeleteRatingOptionAction::class)->handle($this->actor(), $option);
        } catch (CannotDeleteVotedRatingOptionException) {
            $this->confirm($option, 'voted', 'deleted');

            return;
        } catch (InvalidRatingGroupConfigurationException) {
            $this->confirm($option, 'below-min', 'deleted');

            return;
        }

        $this->loaded = null;

        if ($this->optionEditing === $option->id) {
            $this->closeOptionDrawer();
        }

        $this->toast("{$option->label} deleted");
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->confirmingOption = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $group = $this->group();
        $active = $this->activeCount();
        $options = $this->options();
        $problems = $this->rangeProblems($active);
        $this->settingsBlocked = $problems !== [];

        return [
            'creating' => false,
            'group' => $group,
            'active' => $active,
            'problems' => $problems,
            'labelLimit' => self::labelLimit(),
            'descriptionLimit' => self::descriptionLimit(),
            'position' => $this->position($group),
            'votes' => $group->votes()->count(),
            'options' => $options,
            'counts' => [
                'inactive' => count(array_filter($options, fn (array $row): bool => ! $row['active'] && $row['archived'] === null)),
                'archived' => count(array_filter($options, fn (array $row): bool => $row['archived'] !== null)),
            ],
            'translations' => $this->translations($group),
            'drawer' => $this->drawer($options),
            'confirmation' => $this->confirming !== null ? ($options[$this->confirmingOption ?? 0] ?? null) : null,
        ];
    }

    /**
     * Whether Active can change in the drawer, and why not: an archived
     * option stays inactive, a group at its maximum takes no more active
     * options, and one at its minimum cannot spare an active one.
     */
    private function activeLock(?RatingOption $option): ?string
    {
        $group = $this->group();
        $active = $this->activeCount();

        return match (true) {
            $option?->archived_at !== null => 'Archived options stay inactive. Unarchive it from the options table first.',
            ($option === null || ! $option->is_active) && ! $group->hasRoomForActiveOption($active) => "{$group->label} already has {$active} of {$group->max_options} active options. This option stays inactive until another is deactivated or the maximum is raised.",
            $option !== null && $option->is_active && ! $group->canSpareActiveOption($active) => "{$group->label} needs at least {$group->min_options} active options, so this one can’t be turned off right now.",
            default => null,
        };
    }

    private function setOptionActive(mixed $id, bool $active, bool $undo): void
    {
        $this->closeConfirmation();
        $option = $this->findOption($id);

        if ($option === null) {
            $this->refuseMissing();

            return;
        }

        if ($option->archived_at !== null) {
            $this->toast("{$option->label} is archived. Unarchive it first.", 'info');

            return;
        }

        if ($option->is_active === $active) {
            $this->toast("{$option->label} is already ".($active ? 'active' : 'inactive').'.', 'info');

            return;
        }

        try {
            app(UpdateRatingOptionAction::class)->handle($this->actor(), $option, ['is_active' => $active]);
        } catch (InvalidRatingGroupConfigurationException) {
            $this->loaded = null;
            $this->confirm($option, $active ? 'above-max' : 'below-min', 'deactivated');

            return;
        }

        $this->loaded = null;

        if ($undo) {
            $this->toast("{$option->label} is ".($active ? 'active' : 'inactive').' again');

            return;
        }

        $this->toast(
            "{$option->label} ".($active ? 'activated' : 'deactivated'),
            action: ['label' => 'Undo', 'event' => $active ? 'deactivate-rating-option' : 'activate-rating-option', 'params' => ['id' => $option->id, 'undo' => true]],
        );
    }

    private function confirm(RatingOption $option, string $confirming, string $blockedAs): void
    {
        $this->closeOptionDrawer();
        $this->confirming = $confirming;
        $this->confirmingOption = $option->id;
        $this->blockedAs = $blockedAs;
    }

    /** Whether taking this option out of the active ones would leave the group under its minimum. */
    private function cannotSpare(RatingOption $option): bool
    {
        return $option->is_active && ! $this->group()->canSpareActiveOption($this->activeCount());
    }

    /** Refuses the draft's range when it cannot hold the group's active options, field by field. */
    private function refuseRange(RatingGroup $group): bool
    {
        $problems = $this->rangeProblems($group->options()->active()->count());

        foreach ($problems as $field => $message) {
            $this->addError($field, $message);
        }

        return $problems !== [];
    }

    private function fullMessage(): string
    {
        $group = $this->group();

        return "{$group->label} already has {$this->activeCount()} of {$group->max_options} active options. Deactivate another first, or raise the maximum.";
    }

    private function minimumMessage(): string
    {
        $group = $this->group();

        return "{$group->label} needs at least {$group->min_options} active options, so this one can’t be turned off right now.";
    }

    private function refuseMissing(): void
    {
        $this->loaded = null;
        $this->toast('That option no longer exists.', 'error');
    }

    private function group(): RatingGroup
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $group = RatingGroup::query()->find($this->groupId);
        abort_if($group === null, 404);

        return $this->loaded = $group;
    }

    private function activeCount(): int
    {
        return $this->group()->options()->active()->count();
    }

    private function findOption(mixed $id): ?RatingOption
    {
        if (is_string($id) && ctype_digit($id) && $id[0] !== '0') {
            $id = (int) $id;
        }

        return is_int($id) && $id > 0 ? $this->group()->options()->find($id) : null;
    }

    /**
     * The group's options in their order, with their votes.
     *
     * @return array<int, OptionRow>
     */
    private function options(): array
    {
        return $this->group()->options()
            ->withCount('votes')
            ->ordered()
            ->get()
            ->mapWithKeys(fn (RatingOption $option): array => [$option->id => [
                'id' => $option->id,
                'label' => $option->label,
                'description' => (string) $option->description,
                'key' => $option->key,
                'active' => $option->is_active,
                'archived' => $option->archived_at?->format('d M Y'),
                'sort' => $option->sort_order,
                'votes' => (int) $option->votes_count,
            ]])
            ->all();
    }

    /** Where post pages show the group: its place among the active groups, or nowhere. */
    private function position(RatingGroup $group): ?int
    {
        if (! $group->is_active) {
            return null;
        }

        return RatingGroup::query()
            ->active()
            ->where(fn ($query) => $query->where('sort_order', '<', $group->sort_order)
                ->orWhere(fn ($tie) => $tie->where('sort_order', $group->sort_order)->where('id', '<', $group->id)))
            ->count() + 1;
    }

    /**
     * The group's own text in each language the project offers besides
     * English — its label, and its description when it has one — and the way
     * into Translation Center on what it is missing. An inactive group is not
     * in Translation Center until it is active again.
     *
     * @return array{rows: list<array{flag: string, native: string, code: string, state: string, complete: bool}>, url: ?string, reason: ?string}
     */
    private function translations(RatingGroup $group): array
    {
        $status = app(ContentTranslationStatus::class);
        $fields = ['label' => $status->chips($group->label_translations)];

        if (filled($group->description)) {
            $fields['description'] = $status->chips($group->description_translations);
        }

        $rows = [];

        foreach ($fields['label'] as $index => $chip) {
            $missing = array_keys(array_filter($fields, fn (array $chips): bool => $chips[$index]['value'] === null));
            $complete = $missing === [];

            $rows[] = [
                'flag' => $chip['flag'],
                'native' => $chip['native'],
                'code' => $chip['code'],
                'complete' => $complete,
                'state' => match (true) {
                    $complete => isset($fields['description']) ? 'Label and description' : 'Label',
                    count($missing) === count($fields) => isset($fields['description']) ? 'Label and description missing' : 'Label missing',
                    default => ucfirst($missing[0]).' missing',
                },
            ];
        }

        return [
            'rows' => $rows,
            'url' => $group->is_active && $status->canTranslate() ? $status->translateUrl(ProjectContentSection::RatingGroups, $group->id, $fields) : null,
            'reason' => $group->is_active ? null : 'Inactive groups are translated once they are active again.',
        ];
    }

    /**
     * What the option drawer shows: the option it edits as it is now, or none
     * while it creates one, and why Active cannot change.
     *
     * @param  array<int, OptionRow>  $options
     * @return array{row: ?OptionRow, lock: ?string}|null
     */
    private function drawer(array $options): ?array
    {
        if ($this->optionCreating) {
            return ['row' => null, 'lock' => $this->activeLock(null)];
        }

        $row = $options[$this->optionEditing ?? 0] ?? null;

        return $row === null ? null : ['row' => $row, 'lock' => $this->activeLock($this->findOption($row['id']))];
    }
}
