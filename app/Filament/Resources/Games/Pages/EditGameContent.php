<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\ChangeContentSetAction;
use App\Actions\Games\CreateContentSetAction;
use App\Actions\Games\SaveContentSetAction;
use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Enums\GameProfileIcon;
use App\Enums\GameRoundMode;
use App\Enums\GameVersionStatus;
use App\Enums\ImageOrientation;
use App\Enums\PostStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Support\ListPageNumber;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameVersion;
use App\Models\Post;
use App\Queries\Games\GameDraftQuery;
use App\Queries\Games\GamePostPickerQuery;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Services\Games\GamePreviewDrafts;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Support\Games\ContentSetDraftRules;
use App\Support\Games\Drafts\DraftContentChecks;
use App\Support\Games\Drafts\DraftImageState;
use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Versus\CrowdComparison;
use App\Support\Games\Versus\ImageState;
use App\Support\Games\Versus\VersusRoundCheck;
use App\Support\Media\PostImagePresenter;
use App\Support\Settings\ProjectSettingsManager;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A game's Content tab: its content sets — switched between, made empty or
 * as a copy, renamed, archived, restored, deleted while no version used them
 * — and the set on screen: its rounds and, beside them, its Results (scoring
 * and result profiles).
 *
 * The whole set is one draft until Save in the top bar (SaveContentSetAction):
 * rounds added, moved, duplicated or removed, posts chosen, pictures chosen,
 * profiles edited. The browser counts what is unsaved and asks before the page
 * is left with unsaved changes; Discard draws the set again as saved. Each
 * round says, as it stands on screen, whether it can be published and why not
 * (VersusRoundCheck), and Results whether every result has a profile. Beside
 * the round, a live preview plays it as it stands, unsaved edits included:
 * each draw hands the draft to GamePreviewDrafts, and the preview frame plays
 * the draft the stamp names.
 */
final class EditGameContent extends Page
{
    use InteractsWithGameEditor;
    use WithFileUploads;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-content';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Url(as: 'set', except: 0)]
    public int $setId = 0;

    /** Rounds or Results. */
    #[Url(as: 'view', except: 'rounds')]
    public string $pane = 'rounds';

    #[Url(as: 'round', except: 0)]
    public int $selected = 0;

    #[Locked]
    public int $lockVersion = 1;

    #[Locked]
    public bool $stale = false;

    /** @var array<string, mixed> */
    public array $scoring = [];

    /** @var array<int, array<string, mixed>> as the browser sends it back, a list kept as one */
    public array $rounds = [];

    /** @var array<int, array<string, mixed>> */
    public array $profiles = [];

    /** @var array<string, mixed> the draft as saved, for the browser's count of what is unsaved */
    public array $saved = [];

    /** @var array<string, mixed> pictures chosen for options and not saved yet, by `round:{key}:{slot}` */
    public array $uploads = [];

    /** The option the post picker chooses for, `{round key}:{slot}`. */
    public ?string $pickerFor = null;

    public string $pickerSearch = '';

    public int $pickerPage = 1;

    /** The profile the drawer edits, by key. */
    public ?string $editingProfile = null;

    public string $setName = '';

    /** How the live preview frames every pair: `uploaded` as its pictures are, or all landscape, square or portrait. */
    public string $previewShape = 'uploaded';

    /** What the set confirmation on screen asks about: archive or delete. */
    #[Locked]
    public ?string $confirmingSet = null;

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $set = $this->chosenSet($game);
        $this->drawSet($set);
    }

    #[On('discard-game-content')]
    public function discard(): void
    {
        $game = $this->game();

        if ($game === null) {
            return;
        }

        $this->drawSet($this->chosenSet($game));
        $this->resetErrorBag();
    }

    #[On('save-game-content')]
    public function save(): void
    {
        $set = $this->currentSet();

        if ($set === null) {
            $this->toast('This content set no longer exists.', 'error');

            return;
        }

        $uploads = array_filter($this->uploads, fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

        try {
            app(SaveContentSetAction::class)->handle($this->admin(), $set, $this->draft(), $this->lockVersion, $uploads);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->openFirstProfileWithErrors();
            $this->toast('Some fields need fixing before the set can be saved.', 'error');

            return;
        } catch (ImageIngestException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->drawSet($set->fresh() ?? $set);
        $this->toast('Content saved');
    }

    public function reload(): void
    {
        $this->discard();
    }

    // Rounds

    public function selectRound(mixed $index): void
    {
        if (is_numeric($index) && isset($this->rounds[(int) $index])) {
            $this->selected = (int) $index;
            $this->pane = 'rounds';
        }
    }

    public function addRound(): void
    {
        if (count($this->rounds) >= ContentSetDraftRules::MOST_ROUNDS) {
            $this->toast('A set holds at most '.ContentSetDraftRules::MOST_ROUNDS.' rounds.', 'error');

            return;
        }

        $this->rounds[] = ContentSetDraft::newRound('n'.Str::lower(Str::random(10)), count($this->rounds) + 1);
        $this->selected = count($this->rounds) - 1;
        $this->pane = 'rounds';
    }

    public function duplicateRound(mixed $index): void
    {
        if (! is_numeric($index) || ! isset($this->rounds[(int) $index]) || count($this->rounds) >= ContentSetDraftRules::MOST_ROUNDS) {
            return;
        }

        $copy = $this->rounds[(int) $index];
        $copy['id'] = null;
        $copy['key'] = 'n'.Str::lower(Str::random(10));
        $copy['internal_name'] = Str::limit($copy['internal_name'].' copy', ContentSetDraftRules::INTERNAL_NAME_MAX, '');
        // An uploaded picture is not shared by a copy until it is saved again.
        array_splice($this->rounds, (int) $index + 1, 0, [$copy]);
        $this->selected = (int) $index + 1;
    }

    public function removeRound(mixed $index): void
    {
        if (! is_numeric($index) || ! isset($this->rounds[(int) $index])) {
            return;
        }

        $key = $this->rounds[(int) $index]['key'];
        unset($this->uploads["round:{$key}:0"], $this->uploads["round:{$key}:1"]);
        // The round on screen stays on screen when another one goes.
        $shown = $this->rounds[$this->selected]['key'] ?? null;
        array_splice($this->rounds, (int) $index, 1);
        $at = array_search($shown, array_column($this->rounds, 'key'), true);
        $this->selected = is_int($at) ? $at : max(0, min($this->selected, count($this->rounds) - 1));
    }

    public function moveRound(mixed $index, mixed $by): void
    {
        $from = is_numeric($index) ? (int) $index : -1;

        $this->dropRound($from, $from + (is_numeric($by) ? (int) $by : 0));
    }

    /** A round dragged to another place: the rounds between close up, and the round on screen stays on screen. */
    public function dropRound(mixed $from, mixed $to): void
    {
        $from = is_numeric($from) ? (int) $from : -1;
        $to = is_numeric($to) ? (int) $to : -1;

        if (! isset($this->rounds[$from]) || ! isset($this->rounds[$to]) || $from === $to) {
            return;
        }

        $shown = $this->rounds[$this->selected]['key'] ?? null;
        $moved = array_splice($this->rounds, $from, 1);
        array_splice($this->rounds, $to, 0, $moved);
        $at = array_search($shown, array_column($this->rounds, 'key'), true);
        $this->selected = is_int($at) ? $at : $to;
    }

    public function setSource(mixed $index, mixed $slot, mixed $source): void
    {
        $source = is_string($source) ? GameOptionSource::tryFrom($source) : null;

        if (! $this->hasOption($index, $slot) || $source === null || $this->rounds[(int) $index]['options'][(int) $slot]['source'] === $source->value) {
            return;
        }

        $key = $this->rounds[(int) $index]['key'];
        unset($this->uploads["round:{$key}:{$slot}"]);
        $this->rounds[(int) $index]['options'][(int) $slot] = [
            ...$this->rounds[(int) $index]['options'][(int) $slot],
            'source' => $source->value,
            'post_id' => null,
            'asset_id' => null,
        ];
    }

    public function markCorrect(mixed $index, mixed $slot): void
    {
        if (! $this->hasOption($index, $slot)) {
            return;
        }

        foreach ($this->rounds[(int) $index]['options'] as $position => $option) {
            $this->rounds[(int) $index]['options'][$position]['is_correct'] = $position === (int) $slot;
        }

        if ($this->rounds[(int) $index]['mode'] === GameRoundMode::Crowd->value) {
            $this->rounds[(int) $index]['mode'] = GameRoundMode::Objective->value;
        }
    }

    public function useCorrectAnswer(mixed $index): void
    {
        if (is_numeric($index) && isset($this->rounds[(int) $index])) {
            $this->rounds[(int) $index]['mode'] = GameRoundMode::Objective->value;
        }
    }

    public function removeImage(mixed $index, mixed $slot): void
    {
        if (! $this->hasOption($index, $slot)) {
            return;
        }

        $key = $this->rounds[(int) $index]['key'];
        unset($this->uploads["round:{$key}:{$slot}"]);
        $this->rounds[(int) $index]['options'][(int) $slot]['asset_id'] = null;
        $this->rounds[(int) $index]['options'][(int) $slot]['post_id'] = null;
    }

    /** A picture chosen for an option: checked now, stored with the next Save. */
    public function updatedUploads(mixed $value, string $key): void
    {
        try {
            $this->validate(["uploads.{$key}" => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max((int) config('uploads.images.max_kilobytes', 5120))]], [], ["uploads.{$key}" => 'picture']);
        } catch (ValidationException $exception) {
            unset($this->uploads[$key]);

            throw $exception;
        }

        if (preg_match('/\Around:([a-z0-9]+):([01])\z/', $key, $match) === 1) {
            foreach ($this->rounds as $index => $round) {
                if ($round['key'] === $match[1]) {
                    $this->rounds[$index]['options'][(int) $match[2]]['source'] = GameOptionSource::Upload->value;
                    $this->rounds[$index]['options'][(int) $match[2]]['post_id'] = null;
                }
            }
        }
    }

    // Post picker

    public function openPicker(mixed $index, mixed $slot): void
    {
        if (! $this->hasOption($index, $slot)) {
            return;
        }

        $this->pickerFor = $this->rounds[(int) $index]['key'].':'.(int) $slot;
        $this->pickerSearch = '';
        $this->pickerPage = 1;
    }

    public function closePicker(): void
    {
        $this->pickerFor = null;
    }

    public function updatedPickerSearch(): void
    {
        $this->pickerPage = 1;
    }

    public function pickerGoToPage(mixed $page): void
    {
        $this->pickerPage = ListPageNumber::from($page);
    }

    /** The page is the browser's to set as well, so it is held to a page number either way. */
    public function updatedPickerPage(): void
    {
        $this->pickerPage = ListPageNumber::from($this->pickerPage);
    }

    public function pickPost(mixed $postId): void
    {
        if ($this->pickerFor === null || ! is_numeric($postId)) {
            return;
        }

        [$key, $slot] = explode(':', $this->pickerFor);
        $post = Post::query()->published()->find((int) $postId);

        if ($post === null) {
            $this->toast('That post is no longer published.', 'error');

            return;
        }

        foreach ($this->rounds as $index => $round) {
            if ($round['key'] === $key) {
                unset($this->uploads["round:{$key}:{$slot}"]);
                $this->rounds[$index]['options'][(int) $slot] = [
                    ...$round['options'][(int) $slot],
                    'source' => GameOptionSource::Post->value,
                    'post_id' => (int) $post->id,
                    'asset_id' => null,
                ];
            }
        }

        $this->pickerFor = null;
    }

    // Results

    public function showView(mixed $view): void
    {
        $this->pane = $view === 'results' ? 'results' : 'rounds';
    }

    public function addProfile(): void
    {
        if (count($this->profiles) >= ContentSetDraftRules::MOST_PROFILES) {
            return;
        }

        $scored = $this->checks()->scoredRounds;
        $key = 'new-'.Str::lower(Str::random(6));
        $this->profiles[] = ['id' => null, 'key' => $key, 'name' => 'New profile', 'description' => '', 'min' => $scored, 'max' => $scored, 'icon' => GameProfileIcon::Award->value, 'share_text' => 'I got {profile} in {game}.', 'share_asset_id' => null];
        $this->editingProfile = $key;
    }

    /** Four profiles that cover every result once, for a set with none. */
    public function addSuggestedProfiles(): void
    {
        if ($this->profiles !== []) {
            return;
        }

        $scored = max(1, $this->checks()->scoredRounds);
        $names = ResultProfileCoverage::suggestedNames($scored);

        foreach (ResultProfileCoverage::suggestedRanges($scored) as $index => $range) {
            $suggested = ResultProfileCoverage::SUGGESTED[$names[$index]];
            $this->profiles[] = [
                'id' => null,
                'key' => Str::slug($suggested['name']),
                'name' => $suggested['name'],
                'description' => $suggested['description'],
                'min' => $range['min'],
                'max' => $range['max'],
                'icon' => $suggested['icon'],
                'share_text' => 'I got {profile} in {game}.',
                'share_asset_id' => null,
            ];
        }
    }

    /**
     * Spreads the profiles there are over 0 to the scored rounds, in their
     * order, as suggested ranges would — asked for, never done on its own.
     */
    public function fitRanges(): void
    {
        $count = count($this->profiles);

        if ($count === 0) {
            return;
        }

        $scored = max(1, $this->checks()->scoredRounds);
        $ranges = $count === count(ResultProfileCoverage::suggestedRanges($scored))
            ? ResultProfileCoverage::suggestedRanges($scored)
            : self::evenRanges($count, $scored);

        foreach ($ranges as $index => $range) {
            $this->profiles[$index]['min'] = $range['min'];
            $this->profiles[$index]['max'] = $range['max'];
        }
    }

    public function editProfile(mixed $key): void
    {
        $this->editingProfile = is_string($key) && $this->profileIndex($key) !== null ? $key : null;
    }

    public function closeProfile(): void
    {
        $this->editingProfile = null;
    }

    public function removeProfile(mixed $key): void
    {
        $index = is_string($key) ? $this->profileIndex($key) : null;

        if ($index !== null) {
            unset($this->uploads['profile:'.$key]);
            array_splice($this->profiles, $index, 1);
            $this->editingProfile = null;
        }
    }

    public function removeShareImage(mixed $key): void
    {
        $index = is_string($key) ? $this->profileIndex($key) : null;

        if ($index !== null) {
            $this->profiles[$index]['share_asset_id'] = null;
            unset($this->uploads['profile:'.$key]);
        }
    }

    // Sets

    public function createSet(mixed $copy = false): void
    {
        $game = $this->game();

        if ($game === null) {
            return;
        }

        try {
            // The action trims the name to its limit.
            $name = $copy ? $this->currentSet()?->name.' copy' : 'New set';
            $set = app(CreateContentSetAction::class)->handle($this->admin(), $game, $name, $copy ? $this->setId : null);
        } catch (CannotManageGameException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        session()->flash('rg-admin-toast', ['message' => $copy ? $set->name.' added as a copy' : 'New empty set added', 'tone' => 'success']);
        $this->redirect(GameResource::getUrl('content', ['record' => $game, 'set' => $set->id]));
    }

    public function renameSet(): void
    {
        $set = $this->currentSet();

        if ($set === null) {
            return;
        }

        try {
            app(ChangeContentSetAction::class)->rename($this->admin(), $set, $this->setName, $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The rename moved the set on from the version on screen, and nothing else did.
        $this->lockVersion++;
        $this->toast('Content set renamed');
    }

    public function askSet(mixed $action): void
    {
        $this->confirmingSet = in_array($action, ['archive', 'delete'], true) ? $action : null;
    }

    public function closeSet(): void
    {
        $this->confirmingSet = null;
    }

    /**
     * Archives or deletes the set on screen, and opens the Content tab again.
     * The confirm told the page it is leaving, so nothing unsaved asks first;
     * a change refused takes that back.
     */
    public function confirmSet(): void
    {
        $action = $this->confirmingSet;
        $this->confirmingSet = null;
        $set = $this->currentSet();
        $game = $this->game();

        if ($set === null || $game === null || $action === null) {
            $this->js('$store.adminEditor.leaving = false');

            return;
        }

        try {
            $action === 'delete'
                ? app(ChangeContentSetAction::class)->delete($this->admin(), $set)
                : app(ChangeContentSetAction::class)->archive($this->admin(), $set);
        } catch (CannotManageGameException $exception) {
            $this->js('$store.adminEditor.leaving = false');
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        session()->flash('rg-admin-toast', ['message' => $action === 'delete' ? 'Content set deleted' : 'Content set archived', 'tone' => 'success']);
        $this->redirect(GameResource::getUrl('content', ['record' => $game]));
    }

    /** Restores the set on screen, or an archived one from the set menu, which then opens. */
    public function restoreSet(mixed $setId = null): void
    {
        $other = is_numeric($setId) && (int) $setId !== $this->setId;
        $set = $other
            ? GameContentSet::query()->where('game_id', $this->gameId)->find((int) $setId)
            : $this->currentSet();

        if ($set === null) {
            return;
        }

        try {
            app(ChangeContentSetAction::class)->restore($this->admin(), $set, $other ? null : $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            $this->stale = ! $other && $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        if ($other) {
            session()->flash('rg-admin-toast', ['message' => $set->name.' restored', 'tone' => 'success']);
            $this->redirect(GameResource::getUrl('content', ['record' => $this->gameId, 'set' => $set->id]));

            return;
        }

        // Restoring changes no round: what is being edited stays, unsaved edits and pictures included.
        $this->lockVersion++;
        $this->toast('Content set restored');
    }

    // Drawing

    private function chosenSet(Game $game): GameContentSet
    {
        $sets = GameContentSet::query()->where('game_id', $game->id)->orderBy('position')->orderBy('id')->get();
        $set = $sets->firstWhere('id', $this->setId) ?? $sets->firstWhere('archived_at', null) ?? $sets->first();
        abort_if($set === null, 404);

        return $set;
    }

    private function currentSet(): ?GameContentSet
    {
        return GameContentSet::query()->where('game_id', $this->gameId)->find($this->setId);
    }

    /** Draws the set as saved: what was changed, and the pictures chosen but not stored, are dropped. */
    private function drawSet(GameContentSet $set): void
    {
        $set = app(GameDraftQuery::class)->contentSet((int) $set->game_id, (int) $set->id) ?? $set;
        $draft = ContentSetDraft::of($set);

        $this->uploads = [];
        $this->setId = (int) $set->id;
        $this->setName = $set->name;
        $this->lockVersion = $set->lock_version;
        $this->stale = false;
        $this->scoring = $draft->scoring;
        $this->rounds = $draft->rounds;
        $this->profiles = $draft->profiles;
        $this->saved = ['scoring' => $draft->scoring, 'rounds' => $draft->rounds, 'profiles' => $draft->profiles];
        $this->selected = max(0, min($this->selected, count($this->rounds) - 1));
    }

    private function draft(): ContentSetDraft
    {
        return new ContentSetDraft(
            scoring: [
                'points_per_round' => (int) ($this->scoring['points_per_round'] ?? 1),
                'crowd_min_votes' => (int) ($this->scoring['crowd_min_votes'] ?? config('games.crowd.min_votes')),
                'crowd_min_gap' => (int) ($this->scoring['crowd_min_gap'] ?? config('games.crowd.min_gap')),
            ],
            rounds: array_values($this->rounds),
            profiles: array_values($this->profiles),
        );
    }

    private function checks(): DraftContentChecks
    {
        $draft = $this->draft();
        $postIds = [];
        $assetIds = [];

        foreach ($draft->rounds as $round) {
            foreach ($round['options'] as $option) {
                if (! empty($option['post_id'])) {
                    $postIds[] = (int) $option['post_id'];
                }

                if (! empty($option['asset_id'])) {
                    $assetIds[] = (int) $option['asset_id'];
                }
            }
        }

        $query = app(GameDraftQuery::class);

        return DraftContentChecks::of($draft, $query->posts($postIds), $query->assets($assetIds), array_keys(array_filter($this->uploads)));
    }

    private function hasOption(mixed $index, mixed $slot): bool
    {
        return is_numeric($index) && is_numeric($slot) && isset($this->rounds[(int) $index]['options'][(int) $slot]);
    }

    /** A profile's field errors show in its drawer only, so a save they refused opens the first such profile there. */
    private function openFirstProfileWithErrors(): void
    {
        foreach (array_values($this->profiles) as $index => $profile) {
            if ($this->getErrorBag()->has('profiles.'.$index.'.*')) {
                $this->editingProfile = (string) $profile['key'];

                return;
            }
        }
    }

    private function profileIndex(string $key): ?int
    {
        foreach ($this->profiles as $index => $profile) {
            if ($profile['key'] === $key) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<array{min: int, max: int}> */
    private static function evenRanges(int $count, int $total): array
    {
        $ranges = [];
        $start = 0;

        for ($index = 0; $index < $count; $index++) {
            $left = $count - $index;
            $span = intdiv($total + 1 - $start + $left - 1, $left);
            $end = $index === $count - 1 ? $total : min($total, $start + max(1, $span) - 1);
            $ranges[] = ['min' => min($start, $total), 'max' => max(min($start, $total), $end)];
            $start = $end + 1;
        }

        return $ranges;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $checks = $this->checks();
        $sets = GameContentSet::query()->where('game_id', $game->id)->withCount('rounds')->with(['versions' => fn ($query) => $query->orderBy('number')])->orderBy('position')->orderBy('id')->get();
        $set = $sets->firstWhere('id', $this->setId);
        $draftQuery = app(GameDraftQuery::class);
        $posts = $draftQuery->posts(collect($this->rounds)->flatMap(fn (array $round) => array_column($round['options'], 'post_id'))->filter()->map(fn (mixed $id): int => (int) $id)->values()->all());
        $assets = $draftQuery->assets(collect($this->rounds)->flatMap(fn (array $round) => array_column($round['options'], 'asset_id'))->merge(array_column($this->profiles, 'share_asset_id'))->filter()->map(fn (mixed $id): int => (int) $id)->values()->all());
        $presenter = app(PostImagePresenter::class);

        $round = $this->rounds[$this->selected] ?? null;
        $roundCheck = $round !== null ? ($checks->rounds[(string) $round['key']] ?? null) : null;

        return [
            'header' => $this->editorHeader($game, 'content', $this->tabBadges($checks), $this->setId > 0 ? app(GameUnpublishedChangesQuery::class)->forSet($game, $this->setId) : null),
            'sets' => $sets->map(fn (GameContentSet $item): array => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'use' => $this->setUse($item, (int) $item->id === $this->setId ? count($this->rounds) : (int) $item->rounds_count),
                'archived' => $item->isArchived(),
                'url' => GameResource::getUrl('content', ['record' => $game, 'set' => $item->id]),
                'current' => (int) $item->id === $this->setId,
            ])->all(),
            'setArchived' => $set?->isArchived() ?? false,
            'setManage' => $set === null ? null : $this->setManagement($set, $sets->whereNull('archived_at')->count()),
            'resultIssues' => count($checks->profileIssues),
            'checks' => $checks,
            'roundRows' => array_map(fn (array $item, int $index): array => [
                'index' => $index,
                'name' => $item['internal_name'],
                'issues' => isset($checks->rounds[(string) $item['key']]) ? $checks->rounds[(string) $item['key']]->issues : [],
                'unscored' => (bool) $item['unscored'],
            ], $this->rounds, array_keys($this->rounds)),
            'round' => $round,
            'roundCheck' => $roundCheck,
            'options' => $round === null ? [] : array_map(fn (array $option, int $slot): array => $this->optionView($round, $option, $slot, $posts, $assets, $presenter, $roundCheck), $round['options'], array_keys($round['options'])),
            'crowd' => $roundCheck?->crowd === null ? null : $this->crowdView($roundCheck->crowd, $round),
            'limits' => [
                'question' => ContentSetDraftRules::QUESTION_MAX,
                'description' => ContentSetDraftRules::DESCRIPTION_MAX,
                'reveal' => ContentSetDraftRules::REVEAL_MAX,
                'label' => ContentSetDraftRules::LABEL_MAX,
                'profileDescription' => ContentSetDraftRules::PROFILE_DESCRIPTION_MAX,
                'shareText' => ContentSetDraftRules::SHARE_TEXT_MAX,
            ],
            'icons' => array_map(fn (GameProfileIcon $icon): string => $icon->value, GameProfileIcon::cases()),
            'profileRows' => $this->profileRows($checks, $assets, $presenter),
            'editing' => $this->editingProfile !== null ? $this->profiles[$this->profileIndex($this->editingProfile) ?? -1] ?? null : null,
            'editingIndex' => $this->editingProfile !== null ? $this->profileIndex($this->editingProfile) : null,
            'picker' => $this->pickerView($checks),
            'siteName' => app(ProjectSettingsManager::class)->current()->siteName(),
            // The round on screen as it stands, unsaved edits included: the frame plays the draft handed over here.
            'liveUrl' => $this->rounds === [] ? null : route('games.preview', [
                'game' => $game->id,
                'set' => $this->setId,
                'start' => 'round:'.$this->selected,
                'framed' => 1,
                'shape' => ImageOrientation::tryFrom($this->previewShape)?->value,
                'draft' => app(GamePreviewDrafts::class)->put((int) auth()->id(), $this->setId, $this->draft(), $this->chosenPictures()),
            ]),
            'fullPreviewUrl' => GameResource::getUrl('preview', ['record' => $game, 'set' => $this->setId]),
        ];
    }

    /** A set's rounds and the versions made from it: “5 rounds · Used in V1, V3”. */
    private function setUse(GameContentSet $set, int $rounds): string
    {
        $numbers = fn (GameVersionStatus $status): string => $set->versions->where('status', $status)->map(fn (GameVersion $version): string => 'V'.$version->number)->implode(', ');
        $active = $numbers(GameVersionStatus::Active);
        $retired = $numbers(GameVersionStatus::Retired);

        return $rounds.' '.Str::plural('round', $rounds).' · '.match (true) {
            $active !== '' => 'Used in '.$active,
            $retired !== '' => 'Only in retired '.$retired,
            default => 'Not in any version',
        };
    }

    /**
     * What the set on screen may become, and why: a set no version used may be
     * deleted, one a version used only archived, and a game's last set neither.
     *
     * @return array{delete: bool, archive: bool, note: string}
     */
    private function setManagement(GameContentSet $set, int $unarchived): array
    {
        $active = $set->versions->where('status', GameVersionStatus::Active)->map(fn (GameVersion $version): string => 'V'.$version->number)->implode(', ');
        $used = $set->versions->isNotEmpty();
        $only = ! $set->isArchived() && $unarchived <= 1;

        return [
            'delete' => ! $only && ! $used,
            'archive' => ! $only && $used && ! $set->isArchived(),
            'note' => match (true) {
                $only => 'This is the only set. Add another before deleting or archiving it.',
                ! $used => 'No version uses this set, so it can be deleted.',
                $set->isArchived() => 'Archived: it can’t be published until it is restored.',
                $active !== '' => 'Used in '.$active.', so it can’t be deleted. Archiving hides it from new versions; '.$active.' keeps its own copy.',
                default => 'Only retired versions used it, so it can’t be deleted. Archiving hides it from new versions; their copies stay.',
            },
        ];
    }

    /**
     * The pictures chosen for options but not stored until Save, as the live
     * preview shows them: by "round index:option position".
     *
     * @return array<string, array{src: string, width: int|null, height: int|null}>
     */
    private function chosenPictures(): array
    {
        $keys = array_column($this->rounds, 'key');
        $pictures = [];

        foreach ($this->uploads as $place => $upload) {
            if (! $upload instanceof TemporaryUploadedFile || ! $upload->isPreviewable() || preg_match('/\Around:(.+):(\d+)\z/', (string) $place, $match) !== 1) {
                continue;
            }

            $index = array_search($match[1], $keys, true);

            if (! is_int($index)) {
                continue;
            }

            $size = rescue(fn () => $upload->dimensions(), null, false);
            $pictures[$index.':'.$match[2]] = [
                'src' => $upload->temporaryUrl(),
                'width' => is_array($size) && is_int($size[0] ?? null) ? $size[0] : null,
                'height' => is_array($size) && is_int($size[1] ?? null) ? $size[1] : null,
            ];
        }

        return $pictures;
    }

    /** @return array<string, string|null> */
    private function tabBadges(DraftContentChecks $checks): array
    {
        $broken = $checks->roundsWithIssues() + ($checks->setIssues !== [] ? 1 : 0);

        return ['content' => $broken > 0 ? (string) $broken : null];
    }

    /**
     * @param  array<string, mixed>  $round
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function optionView(array $round, array $option, int $slot, $posts, $assets, PostImagePresenter $presenter, ?VersusRoundCheck $check): array
    {
        $upload = $this->uploads["round:{$round['key']}:{$slot}"] ?? null;
        $post = ! empty($option['post_id']) ? $posts->get((int) $option['post_id']) : null;
        $asset = ! empty($option['asset_id']) ? $assets->get((int) $option['asset_id']) : null;
        $preview = match (true) {
            $upload instanceof TemporaryUploadedFile => $upload->isPreviewable() ? $upload->temporaryUrl() : null,
            $option['source'] === GameOptionSource::Upload->value => $presenter->assetThumbnailUrl($asset),
            default => $post !== null ? $presenter->assetThumbnailUrl($post->imageAsset) : null,
        };
        $votes = $post !== null ? (int) $post->upvotes_count + (int) $post->downvotes_count : 0;

        return [
            'slot' => $slot,
            'name' => VersusRoundCheck::optionName($slot),
            'source' => $option['source'],
            'label' => $option['label'],
            'isCorrect' => (bool) $option['is_correct'],
            'preview' => $preview,
            'pending' => $upload instanceof TemporaryUploadedFile,
            'post' => $post === null ? null : [
                'id' => (int) $post->id,
                'title' => (string) $post->title,
                'author' => $post->user?->username,
                'share' => $votes > 0 ? number_format($post->upvotes_count * 100 / $votes, 1) : null,
                'votes' => $votes,
            ],
            'objective' => $check?->mode === GameRoundMode::Objective,
            'answer' => $check?->answer === $slot,
            'problem' => $option['source'] === GameOptionSource::Post->value ? self::postProblem($post) : null,
        ];
    }

    /**
     * What keeps a chosen post out of a round: `gone` once it left the site,
     * `picture` when its picture can't be shown, `processing` while the
     * picture is still being prepared; null when nothing does.
     */
    private static function postProblem(?Post $post): ?string
    {
        if ($post === null) {
            return null;
        }

        if ($post->trashed() || $post->status !== PostStatus::Published) {
            return 'gone';
        }

        return match (DraftImageState::of($post->imageAsset)) {
            ImageState::Unavailable => 'picture',
            ImageState::Processing => 'processing',
            ImageState::Ready => null,
        };
    }

    /**
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    private function crowdView(CrowdComparison $crowd, array $round): array
    {
        return [
            'state' => $crowd->state->value,
            'a' => ['share' => $crowd->a->displayShare(), 'votes' => $crowd->a->votes()],
            'b' => ['share' => $crowd->b->displayShare(), 'votes' => $crowd->b->votes()],
            'preferred' => $crowd->preferred,
            'minVotes' => $crowd->minVotes,
            'minGap' => $crowd->minGap,
            // The options with fewer votes than the crowd needs — at least one, whatever the limit says.
            'short' => array_keys(array_filter([$crowd->a, $crowd->b], fn ($side): bool => $side->votes() < max(1, $crowd->minVotes))),
            'unscored' => (bool) $round['unscored'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function profileRows(DraftContentChecks $checks, $assets, PostImagePresenter $presenter): array
    {
        $points = max(1, (int) ($this->scoring['points_per_round'] ?? 1));

        return array_map(function (array $profile) use ($assets, $presenter, $points): array {
            $upload = $this->uploads['profile:'.$profile['key']] ?? null;
            $asset = ! empty($profile['share_asset_id']) ? $assets->get((int) $profile['share_asset_id']) : null;

            return [
                'key' => $profile['key'],
                'name' => $profile['name'],
                'description' => $profile['description'],
                'range' => $profile['min'] === $profile['max'] ? (string) $profile['min'] : $profile['min'].'–'.$profile['max'],
                'points' => $points > 1 ? ($profile['min'] * $points).'–'.($profile['max'] * $points).' points' : null,
                'icon' => $profile['icon'],
                'shareText' => $profile['share_text'],
                'shareImage' => $upload instanceof TemporaryUploadedFile ? ($upload->isPreviewable() ? $upload->temporaryUrl() : null) : $presenter->assetThumbnailUrl($asset),
                'hasShareImage' => $upload instanceof TemporaryUploadedFile || $asset !== null,
            ];
        }, $this->profiles);
    }

    /** @return array<string, mixed>|null */
    private function pickerView(DraftContentChecks $checks): ?array
    {
        if ($this->pickerFor === null) {
            return null;
        }

        [$key, $slot] = explode(':', $this->pickerFor);
        $except = [];

        foreach ($this->rounds as $round) {
            if ($round['key'] === $key) {
                $other = $round['options'][1 - (int) $slot]['post_id'] ?? null;
                $except = $other !== null ? [(int) $other] : [];
            }
        }

        $paginator = app(GamePostPickerQuery::class)->paginate($this->pickerSearch, $except, $this->pickerPage);
        $presenter = app(PostImagePresenter::class);
        $minVotes = (int) ($this->scoring['crowd_min_votes'] ?? config('games.crowd.min_votes'));

        return [
            'option' => VersusRoundCheck::optionName((int) $slot),
            'paginator' => $paginator,
            'posts' => array_map(function (Post $post) use ($presenter, $minVotes): array {
                $votes = (int) $post->upvotes_count + (int) $post->downvotes_count;

                return [
                    'id' => (int) $post->id,
                    'title' => (string) $post->title,
                    'author' => $post->user?->username,
                    'thumbnail' => $presenter->assetThumbnailUrl($post->imageAsset),
                    'share' => $votes > 0 ? number_format($post->upvotes_count * 100 / $votes, 1) : null,
                    'votes' => $votes,
                    'enough' => $votes >= $minVotes,
                ];
            }, $paginator->items()),
            'minVotes' => $minVotes,
        ];
    }
}
