<?php

namespace App\Filament\Resources\RatingGroups\Pages;

use App\Actions\Rating\UpdateRatingGroupAction;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Support\ContentTranslationStatus;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Support\Translations\ProjectContentSection;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Rating groups, drawn entirely in Admin v2: every group in the order post
 * pages show them, with its options, votes and active range (STS-04).
 * Activating a group that has the active options its range needs is
 * immediate, with Undo; one that has not is explained, with the way into
 * its editor. Deactivating asks first, since voters stop seeing it.
 *
 * Groups are edited on their own page (EditRatingGroup), which also holds
 * their options. A group is never deleted — its votes are the project's
 * results — so Delete stays in the row menu, unavailable, with the reason.
 *
 * @phpstan-type GroupRow array{id: int, label: string, description: string, key: string, active: bool, sort: int, votes: int, activeOptions: int, inactiveOptions: int, archivedOptions: int, min: int, max: int, valid: bool, translate: ?string, url: string}
 */
final class ManageRatingGroups extends Page
{
    protected static string $resource = RatingGroupResource::class;

    protected string $view = 'filament.pages.rating-groups';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'Rating groups';

    /** The confirmation on screen: deactivate, or why a group cannot be activated yet. */
    #[Locked]
    public ?string $confirming = null;

    /** The group the confirmation on screen is about. */
    #[Locked]
    public ?int $confirmingId = null;

    /** @var array<int, GroupRow>|null what this request read, shared by the header, the table and the dialog */
    private ?array $rows = null;

    public static function canAccess(array $parameters = []): bool
    {
        return RatingGroupResource::canAccess();
    }

    /**
     * Shows a group to voters, when it has the active options its range
     * needs; otherwise the dialog says what it lacks. From a toast's Undo it
     * says so without offering another.
     */
    #[On('activate-rating-group')]
    public function activate(mixed $id = null, mixed $undo = false): void
    {
        $this->closeConfirmation();
        $group = $this->find($id);

        if ($group === null) {
            $this->refuseMissing();

            return;
        }

        if ($group->is_active) {
            $this->toast("{$group->label} is already active.", 'info');

            return;
        }

        try {
            app(UpdateRatingGroupAction::class)->handle($this->actor(), $group, ['is_active' => true]);
        } catch (InvalidRatingGroupConfigurationException) {
            $this->confirming = 'blocked';
            $this->confirmingId = $group->id;

            return;
        }

        $this->rows = null;

        if ($undo === true) {
            $this->toast("{$group->label} is active again");

            return;
        }

        $this->toast("{$group->label} activated", action: ['label' => 'Undo', 'event' => 'deactivate-rating-group', 'params' => ['id' => $group->id, 'undo' => true]]);
    }

    /** Asks before voters stop seeing a group. */
    public function askToDeactivate(mixed $id = null): void
    {
        $this->closeConfirmation();
        $group = $this->find($id);

        if ($group === null) {
            $this->refuseMissing();

            return;
        }

        if (! $group->is_active) {
            $this->toast("{$group->label} is already inactive.", 'info');

            return;
        }

        $this->confirming = 'deactivate';
        $this->confirmingId = $group->id;
    }

    /** Hides a group from voters; its votes stay stored. */
    #[On('deactivate-rating-group')]
    public function deactivate(mixed $id = null, mixed $undo = false): void
    {
        $this->closeConfirmation();
        $group = $this->find($id);

        if ($group === null) {
            $this->refuseMissing();

            return;
        }

        if (! $group->is_active) {
            $this->toast("{$group->label} is already inactive.", 'info');

            return;
        }

        app(UpdateRatingGroupAction::class)->handle($this->actor(), $group, ['is_active' => false]);
        $this->rows = null;

        if ($undo === true) {
            $this->toast("{$group->label} is inactive again");

            return;
        }

        $this->toast("{$group->label} deactivated", action: ['label' => 'Undo', 'event' => 'activate-rating-group', 'params' => ['id' => $group->id, 'undo' => true]]);
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->confirmingId = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->groups();
        $active = array_filter($rows, fn (array $row): bool => $row['active']);
        $status = app(ContentTranslationStatus::class);
        // One slot past the widest range or the most active options, up to the most any range allows.
        $slots = min(RatingGroup::MOST_OPTIONS, max([RatingGroup::FEWEST_OPTIONS, ...array_map(fn (array $row): int => max($row['max'], $row['activeOptions']), $rows)]) + 1);

        return [
            'rows' => $rows,
            // One width for every row: wider still for a group with more active options than that.
            'slotCount' => max([$slots, ...array_column($rows, 'activeOptions')]),
            'stats' => [
                'groups' => count($active),
                'options' => array_sum(array_column($active, 'activeOptions')),
                'votes' => array_sum(array_column($rows, 'votes')),
            ],
            'translationCenter' => $status->canTranslate() ? ContentTranslationStatus::sectionUrl(ProjectContentSection::RatingGroups) : null,
            'confirmation' => $this->confirming !== null ? ($rows[$this->confirmingId ?? 0] ?? null) : null,
            'confirming' => $this->confirming,
        ];
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array{label: string, event: string, params: array<string, mixed>}|null  $action
     */
    private function toast(string $message, string $tone = 'success', ?array $action = null): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    private function refuseMissing(): void
    {
        $this->rows = null;
        $this->toast('That rating group no longer exists.', 'error');
    }

    private function find(mixed $id): ?RatingGroup
    {
        if (is_string($id) && ctype_digit($id) && $id[0] !== '0') {
            $id = (int) $id;
        }

        return is_int($id) && $id > 0 ? RatingGroup::query()->find($id) : null;
    }

    /**
     * Every group in the order post pages show them, with its votes and its
     * options counted by state: active, inactive and archived.
     *
     * @return array<int, GroupRow>
     */
    private function groups(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $status = app(ContentTranslationStatus::class);
        $translate = $status->canTranslate();

        return $this->rows = RatingGroup::query()
            ->withCount([
                'votes',
                'options as active_options_count' => self::activeOptions(...),
                'options as inactive_options_count' => self::inactiveOptions(...),
                'options as archived_options_count' => self::archivedOptions(...),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (RatingGroup $group) use ($status, $translate): array {
                $active = (int) $group->getAttribute('active_options_count');
                $fields = ['label' => $status->chips($group->label_translations)];

                if (filled($group->description)) {
                    $fields['description'] = $status->chips($group->description_translations);
                }

                return [$group->id => [
                    'id' => $group->id,
                    'label' => $group->label,
                    'description' => (string) $group->description,
                    'key' => $group->key,
                    'active' => $group->is_active,
                    'sort' => $group->sort_order,
                    'votes' => (int) $group->votes_count,
                    'activeOptions' => $active,
                    'inactiveOptions' => (int) $group->getAttribute('inactive_options_count'),
                    'archivedOptions' => (int) $group->getAttribute('archived_options_count'),
                    'min' => $group->min_options,
                    'max' => $group->max_options,
                    'valid' => $group->holdsActiveOptions($active),
                    // Translation Center lists the groups visitors can see: an inactive one waits until it is active again.
                    'translate' => $translate && $group->is_active ? $status->translateUrl(ProjectContentSection::RatingGroups, $group->id, $fields) : null,
                    'url' => EditRatingGroup::getUrl(['record' => $group->id]),
                ]];
            })
            ->all();
    }

    /**
     * @param  Builder<RatingOption>  $options
     * @return Builder<RatingOption>
     */
    private static function activeOptions(Builder $options): Builder
    {
        return $options->where('is_active', true);
    }

    /**
     * @param  Builder<RatingOption>  $options
     * @return Builder<RatingOption>
     */
    private static function inactiveOptions(Builder $options): Builder
    {
        return $options->where('is_active', false)->whereNull('archived_at');
    }

    /**
     * @param  Builder<RatingOption>  $options
     * @return Builder<RatingOption>
     */
    private static function archivedOptions(Builder $options): Builder
    {
        return $options->whereNotNull('archived_at');
    }
}
