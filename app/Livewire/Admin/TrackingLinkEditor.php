<?php

namespace App\Livewire\Admin;

use App\Actions\Analytics\SaveTrackingLinkAction;
use App\Enums\GameStatus;
use App\Enums\TrackingLinkDestination;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\Analytics\TrackingLinksTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\TrafficSetting;
use App\Models\User;
use App\Queries\Analytics\TrackingLinksQuery;
use App\Support\Analytics\TrackingLinkUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A tracking link in the drawer over Analytics → Tracking links, as the
 * reference draws it: where it leads — a game, one of the site's pages, or a
 * path or address of the site's own — its internal name, its source and
 * medium, its campaign, content and term, and the address they make, to copy
 * or open, as they are typed. Until the administrator names the link, its
 * name follows its source, campaign and content. What is wrong with a field
 * is said once it holds something, or once a save asked; a set of tags
 * another link carries is said under the address. Saved through
 * SaveTrackingLinkAction; the page closes the drawer and opens the link's
 * details.
 *
 * A link edited keeps the version it was drawn from: a save after somebody
 * else's is refused and says so, and Reload draws the link as it is now.
 * Once any visit came through a link, its address is locked and only its
 * name can change; a duplicate is a new link drawn from another's fields.
 * Every request asks again that the viewer may manage traffic sources.
 */
final class TrackingLinkEditor extends Component
{
    /** The link edited; 0 while a new one is made. */
    #[Locked]
    public int $linkId = 0;

    /** The link a new one is a copy of; 0 for a new one from nothing. */
    #[Locked]
    public int $copyOf = 0;

    /** The link's lock_version as the draft was drawn from it. */
    #[Locked]
    public int $lockVersion = 0;

    /** Whether a visit came through the link edited: then only its name changes. */
    #[Locked]
    public bool $locked = false;

    /** Whether somebody else saved the link since the draft was drawn. */
    #[Locked]
    public bool $stale = false;

    /** Whether the link is gone meanwhile. */
    #[Locked]
    public bool $gone = false;

    /** Whether a save was asked: every field then says what is wrong with it. */
    #[Locked]
    public bool $tried = false;

    /** Whether the administrator named the link: until then its name follows its tags. */
    #[Locked]
    public bool $named = false;

    public mixed $name = '';

    public mixed $destination = 'game';

    public mixed $game = '';

    public mixed $page = '/';

    public mixed $custom = '';

    public mixed $source = '';

    public mixed $sourceCustom = '';

    public mixed $medium = '';

    public mixed $campaign = '';

    public mixed $content = '';

    public mixed $term = '';

    public function boot(): void
    {
        abort_unless(GrowthAnalyticsPage::canAccess(), 403);
    }

    public function mount(int $linkId = 0, int $copyOf = 0): void
    {
        $from = $linkId !== 0 || $copyOf !== 0 ? TrackingLink::query()->find($linkId !== 0 ? $linkId : $copyOf) : null;

        abort_if(($linkId !== 0 || $copyOf !== 0) && $from === null, 404);

        if ($from === null) {
            return;
        }

        $this->draw($from);

        if ($linkId !== 0) {
            $this->linkId = (int) $from->id;
            $this->locked = app(TrackingLinksQuery::class)->hasVisits($from);
        } else {
            $this->copyOf = (int) $from->id;
            $this->name = mb_substr($from->name.' (copy)', 0, TrackingLinkUrl::MAX_NAME);
        }
    }

    /** A name typed is the link's own; cleared, it follows the tags again. Any change clears what a save said about the field. */
    public function updated(string $property): void
    {
        if ($property === 'name') {
            $this->named = is_string($this->name) && trim($this->name) !== '';
        }

        $this->resetErrorBag($property === 'sourceCustom' ? 'source' : ($property === 'custom' || $property === 'game' || $property === 'destination' ? 'destination' : $property));
    }

    /** A locked link keeps leading to its own game, whatever game an update asks for. */
    public function updatedGame(): void
    {
        if ($this->locked) {
            $this->game = (string) TrackingLink::query()->whereKey($this->linkId)->value('game_id');
        }
    }

    public function pickSource(mixed $source): void
    {
        if (! $this->locked && is_string($source) && ($source === TrackingLinkUrl::OTHER_SOURCE || array_key_exists($source, TrackingLinkUrl::SOURCES))) {
            $this->source = $source;
            $this->resetErrorBag(['source', 'content']);
        }
    }

    public function pickMedium(mixed $medium): void
    {
        if (! $this->locked && is_string($medium) && in_array($medium, TrackingLinkUrl::MEDIUMS, true)) {
            $this->medium = $medium;
            $this->resetErrorBag(['medium', 'content']);
        }
    }

    public function pickPage(mixed $page): void
    {
        if (! $this->locked && is_string($page)) {
            $this->page = $page;
            $this->resetErrorBag('destination');
        }
    }

    public function save(): void
    {
        if ($this->gone || $this->stale) {
            return;
        }

        $creating = $this->linkId === 0;
        // A field typed and the save can come in one request: the name follows the tags as they are now, not as the last render drew them.
        $this->followTags(app(TrackingLinksQuery::class)->draft($this->input(), SaveTrackingLinkAction::host())['tags']);

        try {
            $link = app(SaveTrackingLinkAction::class)->handle($this->admin(), $creating ? null : $this->linkId, $this->input(), $creating ? null : $this->lockVersion);
        } catch (ValidationException $exception) {
            $this->tried = true;
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->refuse($exception);

            return;
        }

        $this->dispatch('tracking-link-saved', id: (int) $link->id, message: match (true) {
            // Tags visits already came by make a link that counts them from its first moment.
            $creating => app(TrackingLinksQuery::class)->hasVisits($link) ? 'Tracking link created. Visits with these tags already count for it.' : 'Tracking link created. It has no visits yet.',
            $this->locked => 'Link renamed',
            default => 'Link updated',
        });
    }

    /** Draws the link as it is now, the draft with it. */
    public function reload(): void
    {
        $link = $this->linkId === 0 ? null : TrackingLink::query()->find($this->linkId);

        if ($link === null) {
            $this->gone = $this->linkId !== 0;

            return;
        }

        $this->draw($link);
        $this->locked = app(TrackingLinksQuery::class)->hasVisits($link);
        $this->stale = false;
        $this->tried = false;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $query = app(TrackingLinksQuery::class);
        $draft = $query->draft($this->input(), SaveTrackingLinkAction::host());
        $tags = $draft['tags'];
        $this->followTags($tags);

        $games = $query->games();
        $chosen = $draft['destination'] === TrackingLinkDestination::Game && $draft['game_id'] !== null ? $games->firstWhere('id', $draft['game_id']) : null;
        $url = $draft['path'] === null ? null : TrackingLinkUrl::compose(TrackingLinksTab::origin(), $draft['path'], $tags);
        $tagsRead = ! array_intersect_key($draft['errors'], array_flip(['source', 'medium', 'campaign', 'content', 'term']));
        $holding = $tagsRead && ! $this->locked ? $query->holding($tags, $this->linkId === 0 ? null : $this->linkId) : null;
        $valid = $draft['errors'] === [] && $holding === null;
        $host = SaveTrackingLinkAction::host();

        return view('livewire.admin.tracking-link-editor', [
            'title' => match (true) {
                $this->linkId !== 0 => $this->locked ? 'Rename tracking link' : 'Edit tracking link',
                $this->copyOf !== 0 => 'Duplicate tracking link',
                default => 'Create tracking link',
            },
            'subtitle' => $this->linkId === 0 ? 'Creating a link doesn’t start an ad or post anything.' : 'TL-'.$this->linkId.' · '.($this->locked ? 'has visits' : 'no visits yet'),
            'editable' => ! $this->locked,
            'destinations' => array_combine(array_map(fn (TrackingLinkDestination $each): string => $each->value, TrackingLinkDestination::cases()), array_map(fn (TrackingLinkDestination $each): string => $each->label(), TrackingLinkDestination::cases())),
            'destinationValue' => $draft['destination']->value,
            // Until a game is chosen, the list leads with the prompt the trigger shows.
            'games' => [
                ...($chosen instanceof Game ? [] : [['value' => '', 'label' => 'Choose a game', 'icon' => 'gamepad-2']]),
                ...$games->map(fn (Game $game): array => [
                    'value' => (string) $game->id,
                    'label' => $game->title,
                    'icon' => 'gamepad-2',
                    'meta' => ($game->status === GameStatus::Live ? '' : $game->status->label().' · ').$host.TrackingLinkUrl::gamePath($game->slug),
                ])->values()->all(),
            ],
            'gameValue' => $chosen instanceof Game ? (string) $chosen->id : '',
            'gameLabel' => $chosen instanceof Game ? $chosen->title : null,
            'pages' => $query->pages(),
            'sources' => [...TrackingLinkUrl::SOURCES, TrackingLinkUrl::OTHER_SOURCE => 'Other / Custom'],
            'mediums' => TrackingLinkUrl::MEDIUMS,
            'tags' => $tags,
            'wrong' => $this->shown($draft['errors']),
            'siteNote' => in_array($draft['destination'], [TrackingLinkDestination::Page, TrackingLinkDestination::Custom], true) && ! TrafficSetting::captureFlags()['site']
                ? 'Visits to the site’s own pages are recorded only while Record site visits is on.'
                : null,
            'url' => $url,
            'preview' => match (true) {
                $chosen instanceof Game && Gate::allows('manage-games') => GameResource::getUrl('preview', ['record' => $chosen]),
                $draft['path'] !== null => TrackingLinksTab::origin().$draft['path'],
                default => null,
            },
            'holding' => $holding === null ? null : 'Another link already uses these tags: '.$holding->reference().' (“'.$holding->name.'”). Visits would count for it alone. Change the content or term to tell them apart.',
            'foot' => match (true) {
                $this->locked => ['text' => 'Only the internal name can change.', 'wrong' => false],
                $valid => ['text' => $this->linkId === 0 ? 'Ready to create' : 'Ready to save', 'wrong' => false],
                $draft['errors'] === [] => ['text' => 'Another link carries these tags', 'wrong' => $this->tried],
                $this->tried => ['text' => 'Fix the fields marked in red above', 'wrong' => true],
                default => ['text' => 'Required: destination, source, medium and campaign', 'wrong' => false],
            },
            'submit' => $this->linkId === 0 ? 'Create link' : 'Save',
            'canSave' => ! $this->stale && ! $this->gone,
        ]);
    }

    /**
     * What the drawer says is wrong, by field: what a save said, else what
     * the draft says of a field once it holds something, or of every field
     * once a save was asked.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function shown(array $errors): array
    {
        if ($this->locked) {
            return array_filter(['name' => $this->getErrorBag()->first('name') ?: null]);
        }

        $typed = [
            'destination' => $this->destination === TrackingLinkDestination::Custom->value && is_string($this->custom) && trim($this->custom) !== '',
            'source' => $this->source === TrackingLinkUrl::OTHER_SOURCE && is_string($this->sourceCustom) && trim($this->sourceCustom) !== '',
            'medium' => false,
            'campaign' => is_string($this->campaign) && trim($this->campaign) !== '',
            'content' => is_string($this->content) && trim($this->content) !== '',
            'term' => is_string($this->term) && trim($this->term) !== '',
        ];
        $shown = [];

        foreach (['destination', 'name', 'source', 'medium', 'campaign', 'content', 'term'] as $field) {
            $said = $this->getErrorBag()->first($field);
            $shown[$field] = $said !== '' ? $said : (($this->tried || ($typed[$field] ?? false)) ? ($errors[$field] ?? null) : null);
        }

        return array_filter($shown, fn (?string $error): bool => $error !== null);
    }

    /**
     * Until the administrator names the link, its name is what its source,
     * campaign and content make; a locked link keeps its own.
     *
     * @param  array<string, string>  $tags  the draft's tags, as TrackingLinksQuery::draft() reads them
     */
    private function followTags(array $tags): void
    {
        if (! $this->named && ! $this->locked) {
            $this->name = TrackingLinkUrl::autoName($tags['utm_source'], $tags['utm_campaign'], $tags['utm_content']);
        }
    }

    /**
     * The draft as the action reads it.
     *
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'name' => $this->name,
            'destination' => $this->destination,
            'game' => $this->game,
            'page' => $this->page,
            'custom' => $this->custom,
            'source' => $this->source,
            'source_custom' => $this->sourceCustom,
            'medium' => $this->medium,
            'campaign' => $this->campaign,
            'content' => $this->content,
            'term' => $this->term,
        ];
    }

    /** Says why nothing was saved, and keeps the drawer from saving what can no longer be; a link that got its first visit meanwhile is drawn locked. */
    private function refuse(CannotManageTrafficException $exception): void
    {
        if ($exception->isStale()) {
            $this->stale = true;
        } elseif ($exception->isGone()) {
            $this->gone = true;
        } elseif ($this->linkId !== 0) {
            $name = $this->name;
            $this->reload();
            $this->name = $name;
            $this->named = true;
        }

        $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');
    }

    private function draw(TrackingLink $link): void
    {
        $known = array_key_exists($link->utm_source, TrackingLinkUrl::SOURCES);

        $this->name = $link->name;
        $this->named = true;
        $this->destination = $link->destination->value;
        $this->game = $link->game_id === null ? '' : (string) $link->game_id;
        $this->page = $link->destination === TrackingLinkDestination::Page ? $link->path : '/';
        $this->custom = $link->destination === TrackingLinkDestination::Custom ? $link->path : '';
        $this->source = $known ? $link->utm_source : TrackingLinkUrl::OTHER_SOURCE;
        $this->sourceCustom = $known ? '' : $link->utm_source;
        $this->medium = $link->utm_medium;
        $this->campaign = $link->utm_campaign;
        $this->content = $link->utm_content;
        $this->term = $link->utm_term;
        $this->lockVersion = $link->lock_version;
    }

    private function admin(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
