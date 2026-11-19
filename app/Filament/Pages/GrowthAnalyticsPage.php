<?php

namespace App\Filament\Pages;

use App\Actions\Analytics\LogJourneyLookupAction;
use App\Actions\Analytics\RestoreTrafficSegmentAction;
use App\Enums\TrafficDevice;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\Analytics\AnalyticsReadCache;
use App\Filament\Pages\Analytics\AnalyticsTab;
use App\Filament\Pages\Analytics\AnalyticsView;
use App\Filament\Pages\Analytics\CampaignsTab;
use App\Filament\Pages\Analytics\EngagementTab;
use App\Filament\Pages\Analytics\ExperimentsTab;
use App\Filament\Pages\Analytics\GamesTab;
use App\Filament\Pages\Analytics\GeographyTab;
use App\Filament\Pages\Analytics\JourneysTab;
use App\Filament\Pages\Analytics\LandingsTab;
use App\Filament\Pages\Analytics\OverviewTab;
use App\Filament\Pages\Analytics\RetentionTab;
use App\Filament\Pages\Analytics\TrackingLinksTab;
use App\Filament\Resources\Games\Analytics\ReadsGameAnalytics;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\ReportPeriodMemory;
use App\Models\TrafficSegment;
use App\Models\User;
use App\Queries\Analytics\JourneyQuery;
use App\Queries\Games\ComparisonPeriodsQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use ReflectionClass;
use ReflectionProperty;
use UnitEnum;

/**
 * Growth → Analytics: where visitors come from and what they do on the whole
 * site, counted by RateGuru itself from the visits it records — people, not
 * visits. A period of whole days (its presets, or a range up to the longest
 * Analytics reads at a time), a device, a source and a campaign narrow every
 * figure of the tab open that reads visits — Overview, Site engagement,
 * Campaigns, Landings and Geography; Experiments reads who each traffic
 * period assigned to which version instead, and none of them applies to it;
 * Games is a game's own analytics, of the period and the device with the
 * game's own filters (ReadsGameAnalytics), the source and campaign said not
 * to apply; Retention reads the new accounts and the days they came back,
 * of the period and the device, the source and campaign said not to apply
 * either. Tracking links lists the administrator's tagged addresses with
 * what each brought, and is drawn before any visit is recorded, since links
 * come first. Journeys looks up one visitor's or member's every step by an
 * opaque ID, whatever the period and filters; each lookup is written down
 * (LogJourneyLookupAction) — once an ID is typed, picked or opened from an
 * address, never on the way to one. The strip lists only the tabs built so
 * far.
 *
 * Everything on screen is in the address — the tab, the period, the device,
 * the source and campaign, a tab's own sorting, the two sources Site
 * engagement compares, the creative Campaigns opens, the tracking link it
 * was opened from and the paid source's segment whose drawer is open, the
 * search of Tracking links and the link whose drawer or details are open,
 * the comparison Experiments shows, the game Games shows and its filters,
 * the cut of the cohorts Retention shows, the country Geography opens, the
 * journey Journeys looks up — and anything it can't read is the default;
 * only the rows of the Campaigns tree opened or closed by hand stay with the
 * page alone. The period the page opens on, and every one its picker sets,
 * is kept as the administrator's last choice (ReportPeriodMemory), and an
 * address that names no period opens on it. A segment is saved, deleted and
 * brought back by Undo through its drawer (TrafficSegmentEditor) and the
 * toast it leaves; a tracking link
 * is made and edited through its own (TrackingLinkEditor), whose save opens
 * the link's details. Asking for the page reads no visits: the open tab's
 * figures are read once the page is in a browser (wire:init), and only the
 * open tab's. A source chosen whose campaign isn't one of its own drops the
 * campaign with its creative and tracking link, and another campaign the
 * creative and tracking link of the last. The
 * figures of the tabs slow to read on a busy site (READ_CACHED) are kept for
 * a few minutes once read, for the administrator alone (AnalyticsReadCache):
 * the page says when they were read and offers Refresh, and a segment or
 * tracking link saved, deleted or brought back reads them afresh. The page,
 * and every request it answers, is an active administrator's who may manage
 * traffic sources.
 */
final class GrowthAnalyticsPage extends Page
{
    use ReadsGameAnalytics;

    /** @var array<string, string> the tabs built so far, in the reference's order: the strip lists these and no other */
    public const array TABS = ['overview' => 'Overview', 'engagement' => 'Site engagement', 'campaigns' => 'Campaigns', 'links' => 'Tracking links', 'landings' => 'Landings', 'games' => 'Games', 'experiments' => 'Experiments', 'retention' => 'Retention', 'geo' => 'Geography', 'journeys' => 'Journeys'];

    /** @var array<string, class-string<AnalyticsTab>> what each tab of the strip reads */
    public const array TAB_DATA = ['overview' => OverviewTab::class, 'engagement' => EngagementTab::class, 'campaigns' => CampaignsTab::class, 'links' => TrackingLinksTab::class, 'landings' => LandingsTab::class, 'games' => GamesTab::class, 'experiments' => ExperimentsTab::class, 'retention' => RetentionTab::class, 'geo' => GeographyTab::class, 'journeys' => JourneysTab::class];

    /**
     * @var list<string> the tabs not counted from the visits Analytics
     *                   records — Experiments reads who was assigned to which
     *                   version, Games what playing recorded, saying itself
     *                   what Traffic sources didn't record, Retention the
     *                   accounts and the days they came back, Journeys one
     *                   visitor's or member's every step whatever the period
     *                   — so what the page says about recorded visits, and
     *                   the states it draws for none, don't apply to them
     */
    public const array OWN_STATES = ['games', 'experiments', 'retention', 'journeys'];

    /**
     * @var list<string> the tabs drawn before any visit was recorded, or in
     *                   days before the first: Tracking links makes the links
     *                   visits will come through, so it never waits for them,
     *                   though what the page says about recording holds
     */
    public const array DRAWN_WITHOUT_VISITS = ['links'];

    /**
     * @var list<string> the tabs that say themselves that Record site visits
     *                   is off, drawing what the game visits and paid clicks
     *                   still record: the page's own word about it would only
     *                   repeat theirs
     */
    public const array OWN_SITE_STATE = ['engagement'];

    /** @var list<string> the tabs the source and campaign filters don't apply to: each says so while one is chosen */
    public const array WITHOUT_SOURCE = ['games', 'retention'];

    /**
     * @var list<string> the tabs whose figures are kept for a few minutes
     *                   once read: each took over two seconds to read on 4.5
     *                   million visits over 90 days, the load Analytics was
     *                   built to — Journeys, one visitor's steps, alone stays
     *                   quick
     */
    public const array READ_CACHED = ['overview', 'engagement', 'campaigns', 'links', 'landings', 'games', 'experiments', 'retention', 'geo'];

    /** The name the administrator's last choice of period is kept under (ReportPeriodMemory). */
    public const string PERIOD_MEMORY = 'analytics';

    /** @var array<string, string> how Overview's sources table can be sorted */
    public const array SORTS = ['visitors' => 'Visitors', 'start' => 'Start rate', 'registration' => 'Registration rate', 'activation' => 'Activation rate'];

    protected string $view = 'filament.pages.analytics';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::GROWTH;

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Analytics';

    protected static ?string $slug = 'analytics';

    // After Games (1) and Experiments (2), before Traffic sources (4).
    protected static ?int $navigationSort = 3;

    #[Url(except: 'overview')]
    public mixed $tab = 'overview';

    /**
     * One of AnalyticsPeriod::PRESETS; the default while a range is chosen.
     * Always in the address, the default too: an address naming no period
     * opens on the administrator's last choice instead.
     */
    #[Url(keep: true)]
    public mixed $period = AnalyticsPeriod::DEFAULT;

    /** A range's first day, Y-m-d; none for a preset. */
    #[Url(except: '')]
    public mixed $from = '';

    /** A range's last day, Y-m-d; none for a preset. */
    #[Url(except: '')]
    public mixed $to = '';

    /** A TrafficDevice, or either. */
    #[Url(except: '')]
    public mixed $device = '';

    /** A source's key (AnalyticsSource), or every source. */
    #[Url(except: '')]
    public mixed $source = '';

    /** A campaign's key (CampaignKey), or every campaign. */
    #[Url(except: '')]
    public mixed $campaign = '';

    /** Overview: what its sources table is sorted by, one of SORTS. */
    #[Url(except: 'visitors')]
    public mixed $sort = 'visitors';

    /** Site engagement: the source compared on side A, by its key (AnalyticsSource); the biggest not on side B while none is named or the one named isn't offered. */
    #[Url(except: '')]
    public mixed $a = '';

    /** Site engagement: the source compared on side B, by its key; the biggest not on side A while none is named or the one named isn't offered. */
    #[Url(except: '')]
    public mixed $b = '';

    /** Campaigns: what its campaigns and creatives are sorted by, one of CampaignsTab::SORTS. */
    #[Url(except: 'visitors')]
    public mixed $csort = 'visitors';

    /** Campaigns: the creative of the campaign chosen to open and mark, as its row names it (CampaignsTab::readCreative()); none for the biggest. */
    #[Url(except: '')]
    public mixed $creative = '';

    /**
     * Campaigns: the rows of the tree opened or closed against how it opens
     * to begin with, each as CampaignsTab::node() names it — the latest few,
     * changed only by toggling one.
     *
     * @var list<string>
     */
    #[Locked]
    public array $toggled = [];

    /** Campaigns: the tracking link whose campaign and creative are shown, by its id, with the banner that says so; none when the campaigns weren't opened from a link. */
    #[Url(except: '')]
    public mixed $link = '';

    /** Tracking links: what the list is searched for. */
    #[Url(except: '')]
    public mixed $q = '';

    /** Tracking links: the link whose drawer is open — `new` for one being made, its id, or `copy-{id}` for a copy of one — or none. */
    #[Url(except: '')]
    public mixed $edit = '';

    /** Tracking links: the link whose details are open, by its id, or none. */
    #[Url(except: '')]
    public mixed $show = '';

    /** Campaigns: the segment of the paid source chosen whose drawer is open — `new` for one being made, or its id — or none. */
    #[Url(except: '')]
    public mixed $segment = '';

    /** Experiments: the comparison shown, a traffic period's id; the latest while none is named. */
    #[Url(except: '')]
    public mixed $exp = '';

    /** Games: the game shown, the id of one not archived; the first while none is named or the one named isn't offered. */
    #[Url(except: '')]
    public mixed $game = '';

    /** Retention: the cut of the cohorts shown — a source's key, a game as `game:{id}`, every other source or game, or the accounts that finished no game (RetentionTab::readDim()); every new account while none is named or the one named has no row. */
    #[Url(except: '')]
    public mixed $dim = '';

    /** Geography: what its countries and cities are sorted by, one of GeographyTab::SORTS. */
    #[Url(except: 'visitors')]
    public mixed $gsort = 'visitors';

    /** Geography: the country whose cities are open, by its code, or none (GeographyTab::NONE_OPEN); the one the most people came from while none is named. */
    #[Url(except: '')]
    public mixed $open = '';

    /** Journeys: what is looked up — a visitor_… or user_… ID, or whatever is being typed (JourneyTimeline::read()); the first example's journey while nothing is. */
    #[Url(except: '')]
    public mixed $who = '';

    /** Whether the page is in a browser: only then does the open tab read its figures. */
    public bool $ready = false;

    /** Whether this request reads the open tab's figures afresh rather than as kept: Refresh, or a change the page made itself. */
    private bool $fresh = false;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-traffic-sources');
    }

    /**
     * The period's days in the top bar (a panel render hook), read from the
     * page's own request — or, its address naming none, the administrator's
     * last choice, as the page opens on it; every render after that moves
     * them to the days its figures were read for, by a browser event the page
     * sends — a period chosen, or a preset that rolled over at midnight.
     */
    public static function topbar(Request $request): string
    {
        if (! self::canAccess()) {
            return '';
        }

        $kept = ReportPeriodMemory::recall($request, self::PERIOD_MEMORY);
        $period = self::readPeriod($kept['period'] ?? $request->query('period'), $kept['from'] ?? $request->query('from'), $kept['to'] ?? $request->query('to'));

        return view('filament.pages.analytics.topbar', ['range' => $period->range()])->render();
    }

    public function mount(): void
    {
        // An address that names no period opens on the one last chosen; whichever it opens on is kept in turn.
        $kept = ReportPeriodMemory::recall(request(), self::PERIOD_MEMORY);

        if ($kept !== null) {
            [$this->period, $this->from, $this->to] = [$kept['period'], $kept['from'], $kept['to']];
        }

        $this->normalize();
        $this->rememberPeriod();
    }

    /** The open tab's figures are read once the page is in a browser (wire:init), never by asking for it. */
    public function load(): void
    {
        $this->ready = true;
        $this->dropUnknownComparison();

        // A journey the address names is looked up as the page comes into a browser.
        if ($this->tab === 'journeys') {
            $this->logJourneyLookup('address');
        }
    }

    public function updated(string $property): void
    {
        $this->normalize();

        if ($property === 'source') {
            $this->dropForeignCampaign();
            $this->segment = '';
        }

        // A creative is chosen of its campaign, and a tracking link names both: another campaign, or none, drops them.
        if ($property === 'campaign' || $this->campaign === '') {
            $this->creative = '';
            $this->link = '';
        }

        if ($this->ready && in_array($property, ['tab', 'exp'], true)) {
            $this->dropUnknownComparison();
        }

        // Typed: a lookup once what is typed is an ID, never on the way to one.
        if ($property === 'who') {
            $this->logJourneyLookup('search');
        }
    }

    /**
     * Overview's “See an example journey”: the journey of one of the people
     * its filters keep — the latest account credited to one of their visits,
     * else the latest player of one no account has — read only as it is
     * followed; with neither, Journeys' first example.
     */
    public function openExampleJourney(): void
    {
        if ($this->tab !== 'overview') {
            return;
        }

        $this->pickJourney(app(JourneyQuery::class)->examples($this->filter())[0] ?? '');
    }

    /** An example of Journeys, or one of several browsers sharing an ID's digits: that journey, looked up. */
    public function pickJourney(mixed $who): void
    {
        $this->tab = 'journeys';
        $this->who = $who;
        $this->normalize();
        $this->logJourneyLookup('pick');
    }

    /** Refresh: the open tab's figures read again now, whatever was kept. */
    public function refreshFigures(): void
    {
        $this->fresh = true;
    }

    /**
     * A segment or tracking link the page changed: the open tab's figures
     * read again now, and everything else kept dropped — the change may show
     * on any tab, filter or link details, and to every administrator.
     */
    private function figuresChanged(): void
    {
        $this->fresh = true;
        app(AnalyticsReadCache::class)->forgetAll();
    }

    public function choosePeriod(mixed $preset): void
    {
        $this->period = $preset;
        $this->from = '';
        $this->to = '';
        $this->normalize();
        $this->rememberPeriod();
    }

    public function applyRange(mixed $from, mixed $to): void
    {
        $this->period = AnalyticsPeriod::DEFAULT;
        $this->from = $from;
        $this->to = $to;
        $this->normalize();
        $this->rememberPeriod();
    }

    /** Keeps the period as read as the administrator's last choice in the picker. */
    private function rememberPeriod(): void
    {
        ReportPeriodMemory::remember(self::PERIOD_MEMORY, (string) $this->period, (string) $this->from, (string) $this->to);
    }

    /** A row of Overview's sources table: filters the page by its source, or — chosen already — by every source again. */
    public function pickSource(mixed $key): void
    {
        $key = AnalyticsSource::read($key);

        if ($key === null) {
            return;
        }

        $this->source = $this->source === $key ? '' : $key;
        $this->segment = '';
        $this->dropForeignCampaign();
    }

    /** A paid source's Segments on the Campaigns tab: the page filtered by the source, its segments shown under the paid sources. */
    public function openSegments(mixed $key): void
    {
        $key = AnalyticsSource::read($key);

        if ($this->tab !== 'campaigns' || $key === null || AnalyticsSource::paidSourceId($key) === null) {
            return;
        }

        $this->source = $key;
        $this->segment = '';
        $this->dropForeignCampaign();
    }

    /** Add segment: the drawer of a new segment of the paid source chosen. */
    public function addSegment(): void
    {
        $this->segment = 'new';
        $this->normalize();
    }

    /** A segment's pencil: its drawer. */
    public function editSegment(mixed $id): void
    {
        $this->segment = is_int($id) || is_string($id) ? (string) $id : '';
        $this->normalize();
    }

    /** The drawer's Cancel, Escape or scrim: closed, nothing saved. */
    #[On('close-traffic-segment')]
    public function closeSegment(): void
    {
        $this->segment = '';
    }

    /** Saved in the drawer: it closes, and the page says so. */
    #[On('traffic-segment-saved')]
    public function segmentSaved(mixed $message = ''): void
    {
        $this->segment = '';
        $this->figuresChanged();
        $this->dispatch('rg-admin-toast', message: is_string($message) ? $message : 'Segment saved', tone: 'success');
    }

    /** Deleted in the drawer: it closes, and the page says so with Undo. */
    #[On('traffic-segment-deleted')]
    public function segmentDeleted(mixed $id = null, mixed $name = ''): void
    {
        $this->segment = '';
        $this->figuresChanged();
        $this->dispatch('rg-admin-toast',
            message: 'Segment “'.(is_string($name) ? $name : '').'” deleted',
            tone: 'success',
            action: is_int($id) || (is_string($id) && ctype_digit($id)) ? ['label' => 'Undo', 'event' => 'restore-traffic-segment', 'params' => ['id' => (int) $id]] : null,
        );
    }

    /** Undo after a segment was deleted: it is back on its source's card. */
    #[On('restore-traffic-segment')]
    public function restoreSegment(mixed $id = null): void
    {
        $segment = is_int($id) || (is_string($id) && ctype_digit($id)) ? TrafficSegment::query()->withTrashed()->find((int) $id) : null;
        $user = auth()->user();

        if ($segment === null || ! $user instanceof User) {
            return;
        }

        try {
            app(RestoreTrafficSegmentAction::class)->handle($user, $segment);
        } catch (CannotManageTrafficException $exception) {
            $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');

            return;
        }

        $this->figuresChanged();
        $this->dispatch('rg-admin-toast', message: 'Segment “'.$segment->name.'” is back', tone: 'success');
    }

    /**
     * A campaign's row of the Campaigns tree, or — with its creative as the
     * row names it — a creative's: opened when closed, closed when open.
     * Only the latest few rows toggled are kept, each open one read anew.
     */
    public function toggleCampaignRow(mixed $campaign, mixed $creative = ''): void
    {
        $campaign = CampaignKey::read($campaign);
        $named = $creative === '' ? '' : CampaignsTab::readCreative($creative);

        if ($this->tab !== 'campaigns' || $campaign === null || ($creative !== '' && $named === '')) {
            return;
        }

        $node = CampaignsTab::node($campaign, $named === '' ? null : $named);
        $this->toggled = in_array($node, $this->toggled, true)
            ? array_values(array_filter($this->toggled, fn (string $each): bool => $each !== $node))
            : array_slice([...$this->toggled, $node], -CampaignsTab::MOST_TOGGLED);
    }

    /** The chip's ×: every source again. */
    public function clearSource(): void
    {
        $this->source = '';
        $this->segment = '';
    }

    /** Every device, source and campaign again, the period kept. */
    public function clearFilters(): void
    {
        $this->device = '';
        $this->source = '';
        $this->campaign = '';
        $this->creative = '';
        $this->link = '';
        $this->segment = '';
    }

    /** Campaigns opened from a tracking link: back to the links, the link's campaign no longer chosen. */
    public function backToLinks(): void
    {
        $this->tab = 'links';
        $this->campaign = '';
        $this->creative = '';
        $this->link = '';
        $this->normalize();
    }

    /** Create tracking link: the drawer of a new one. */
    public function createLink(): void
    {
        $this->edit = 'new';
        $this->show = '';
        $this->normalize();
    }

    /** A link's Edit or Rename: its drawer, its details closed. */
    public function editLink(mixed $id): void
    {
        $this->edit = is_int($id) || is_string($id) ? (string) $id : '';
        $this->show = '';
        $this->normalize();
    }

    /** A link's Duplicate: the drawer of a new link made from its fields. */
    public function duplicateLink(mixed $id): void
    {
        $this->edit = is_int($id) || (is_string($id) && ctype_digit($id)) ? 'copy-'.$id : '';
        $this->show = '';
        $this->normalize();
    }

    /** A link's row: its details. */
    public function showLink(mixed $id): void
    {
        $this->show = is_int($id) || is_string($id) ? (string) $id : '';
        $this->edit = '';
        $this->normalize();
    }

    /** The details' close, Escape or scrim. */
    public function closeLinkDetails(): void
    {
        $this->show = '';
    }

    /** The drawer's Cancel, Escape or scrim: closed, nothing saved. */
    #[On('close-tracking-link')]
    public function closeLinkEditor(): void
    {
        $this->edit = '';
    }

    /** Saved in the drawer: it closes, the link's details open, and the page says so. */
    #[On('tracking-link-saved')]
    public function linkSaved(mixed $id = null, mixed $message = ''): void
    {
        $this->edit = '';
        $this->show = is_int($id) || (is_string($id) && ctype_digit($id)) ? (string) $id : '';
        $this->normalize();
        $this->figuresChanged();
        $this->dispatch('rg-admin-toast', message: is_string($message) && $message !== '' ? $message : 'Link saved', tone: 'success');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $filter = $this->filter();
        $period = $filter->period;
        $view = app(AnalyticsView::class);
        $ownStates = in_array($this->tab, self::OWN_STATES, true);
        $recordedSince = $this->ready && ! $ownStates ? $view->recordedSince() : null;

        // Nothing to read yet when no visit was ever recorded, or none until after the period.
        $state = match (true) {
            ! $this->ready => 'loading',
            $ownStates, in_array($this->tab, self::DRAWN_WITHOUT_VISITS, true) => 'ready',
            $recordedSince === null => 'nothing',
            $period->toDay < $recordedSince->toDateString() => 'early',
            default => 'ready',
        };
        $reader = auth()->user();
        $keptFor = $reader instanceof User ? (int) $reader->id : null;
        $shell = $view->shell($filter, $this->ready, $recordedSince, $keptFor, $this->fresh);
        $read = $state === 'ready' ? $this->readTab($filter, $view, $keptFor) : ['data' => null, 'readAt' => null];

        // The top bar's days are the ones these figures are of: a period chosen moves them, and so does a preset that rolled over at midnight.
        $this->dispatch('rg-admin-analytics-range', text: $period->range());

        return [
            ...$shell,
            'notices' => [
                // Whether visits are recorded is nothing to a tab not counted from them; a tab drawn that says itself Record site visits is off says it alone.
                ...($ownStates ? [] : array_values(array_filter($shell['notices'], fn (array $notice): bool => $state !== 'ready' || ! in_array($this->tab, self::OWN_SITE_STATE, true) || $notice['text'] !== AnalyticsView::SITE_OFF))),
                ...(in_array($this->tab, self::WITHOUT_SOURCE, true) && $filter->narrowsSource()
                    ? [['tone' => 'info', 'text' => 'The source and campaign filters don’t apply on this tab.', 'action' => null]]
                    : []),
            ],
            'ready' => $this->ready,
            'state' => $state,
            'since' => $recordedSince?->format('d M Y'),
            'range' => $period->range(),
            'tab' => (string) $this->tab,
            'tabs' => array_map(fn (string $id, string $label): array => ['id' => $id, 'label' => $label, 'attributes' => ['wire:click' => "\$set('tab', '{$id}')"]], array_keys(self::TABS), self::TABS),
            'deviceOptions' => [['value' => '', 'label' => 'All devices', 'trigger' => 'All'], ...array_map(fn (TrafficDevice $device): array => ['value' => $device->value, 'label' => $device->label()], TrafficDevice::cases())],
            'periodPicker' => $this->periodPicker($period),
            'data' => $read['data'],
            'readAt' => $read['readAt']?->format('H:i'),
        ];
    }

    /**
     * The open tab's figures: read now, or — a tab slow to read — as kept
     * for the administrator since they were last read, with that moment.
     *
     * @return array{data: array<string, mixed>, readAt: CarbonImmutable|null}
     */
    private function readTab(AnalyticsFilter $filter, AnalyticsView $view, ?int $keptFor): array
    {
        $tab = (string) $this->tab;
        $read = fn (): array => app()->make(self::TAB_DATA[$tab], ['view' => $view])->data($this, $filter);

        if (! in_array($tab, self::READ_CACHED, true) || $keptFor === null || (int) config('analytics.read_cache_minutes') <= 0) {
            return ['data' => $read(), 'readAt' => null];
        }

        return app(AnalyticsReadCache::class)->remember($keptFor, $tab, $this->readState($filter), $this->fresh, $read);
    }

    /**
     * What the open tab's figures depend on: the days of the period as read
     * today, the address, and the rows of the Campaigns tree toggled — less
     * what the tab ignores, so changing it reads nothing again.
     *
     * @return array<string, mixed>
     */
    private function readState(AnalyticsFilter $filter): array
    {
        // Experiments reads no filter of the page: only the comparison it shows, as of today.
        if ($this->tab === 'experiments') {
            return ['exp' => $this->exp, 'today' => $filter->period->today->toDateString()];
        }

        $state = ['days' => [$filter->fromDay(), $filter->toDay()], 'toggled' => $this->toggled];

        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Url::class) !== []) {
                $state[$property->getName()] = $property->getValue($this);
            }
        }

        // The source and campaign, and what choosing them clears, are nothing to a tab they don't apply to.
        if (in_array($this->tab, self::WITHOUT_SOURCE, true)) {
            unset($state['source'], $state['campaign'], $state['creative'], $state['link'], $state['segment']);
        }

        return $state;
    }

    /** The filter every figure of the tab open reads. */
    private function filter(): AnalyticsFilter
    {
        return new AnalyticsFilter(
            self::readPeriod($this->period, $this->from, $this->to),
            TrafficDevice::tryFrom((string) $this->device),
            $this->source === '' ? null : (string) $this->source,
            $this->campaign === '' ? null : (string) $this->campaign,
        );
    }

    /**
     * A period as the address names it, read for today: the longest range
     * read at a time is Analytics', the oldest day the visits'.
     */
    public static function readPeriod(mixed $preset, mixed $from, mixed $to): AnalyticsPeriod
    {
        return AnalyticsPeriod::read($preset, $from, $to, CarbonImmutable::now('UTC'), (int) config('traffic.retention_days.visits'), (int) config('analytics.max_range_days'));
    }

    /**
     * What the period picker shows and offers: the presets, which is chosen,
     * the days, the range its calendar starts from, and the days a range may
     * take — no earlier than the visits are kept, no longer than Analytics
     * reads.
     *
     * @return array<string, mixed>
     */
    private function periodPicker(AnalyticsPeriod $period): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $kept = (int) config('traffic.retention_days.visits');

        return [
            'presets' => AnalyticsPeriod::PRESETS,
            'value' => $period->preset,
            'label' => $period->label(),
            'dates' => $period->range(),
            'from' => $period->fromDay,
            'to' => $period->toDay,
            'min' => $today->subDays($kept - 1)->toDateString(),
            'max' => $today->toDateString(),
            'most' => (int) config('analytics.max_range_days'),
        ];
    }

    /** Writes down that the journey asked for was looked up, when what is asked for is an ID: Journeys says lookups are logged. */
    private function logJourneyLookup(string $via): void
    {
        $user = auth()->user();

        if ($this->who !== '' && $user instanceof User) {
            app(LogJourneyLookupAction::class)->handle($user, $this->who, $via);
        }
    }

    /** A period the address names that isn't a comparison Experiments lists — one version, hidden, of an archived game, gone — gives way to the latest. */
    private function dropUnknownComparison(): void
    {
        if ($this->exp !== '' && ! app(ComparisonPeriodsQuery::class)->isComparison((int) $this->exp)) {
            $this->exp = '';
        }
    }

    /** A campaign that isn't the chosen source's is dropped, with the creative and tracking link chosen of it: both filters always hold together. */
    private function dropForeignCampaign(): void
    {
        if ($this->campaign !== '' && $this->source !== '' && ! app(AnalyticsView::class)->campaignBelongs($this->filter())) {
            $this->campaign = '';
            $this->creative = '';
            $this->link = '';
        }
    }

    /** Whether an address names a record by its id. */
    private static function isId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9]\d{0,17}$/', (string) $value) === 1;
    }

    /** Puts back in the address what it says as read: anything the page can't read is the default. */
    private function normalize(): void
    {
        $this->tab = is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';

        $period = self::readPeriod($this->period, $this->from, $this->to);
        $this->period = $period->isCustom() ? AnalyticsPeriod::DEFAULT : $period->preset;
        $this->from = $period->isCustom() ? $period->fromDay : '';
        $this->to = $period->isCustom() ? $period->toDay : '';

        $this->device = is_string($this->device) ? (TrafficDevice::tryFrom($this->device)->value ?? '') : '';
        $this->source = AnalyticsSource::read($this->source) ?? '';
        $this->campaign = CampaignKey::read($this->campaign) ?? '';
        $this->sort = is_string($this->sort) && array_key_exists($this->sort, self::SORTS) ? $this->sort : 'visitors';
        // The comparison is the Experiments tab's alone.
        $this->exp = $this->tab === 'experiments' && (is_int($this->exp) || is_string($this->exp)) && preg_match('/^[1-9]\d{0,17}$/', (string) $this->exp) === 1 ? (string) $this->exp : '';

        // The sorting, the creative and the rows toggled are the Campaigns tab's alone; a creative only of a campaign chosen.
        $this->csort = $this->tab === 'campaigns' && is_string($this->csort) && array_key_exists($this->csort, CampaignsTab::SORTS) ? $this->csort : 'visitors';
        $this->creative = $this->tab === 'campaigns' && $this->campaign !== '' ? CampaignsTab::readCreative($this->creative) : '';
        $this->link = $this->tab === 'campaigns' && $this->campaign !== '' && self::isId($this->link) ? (string) $this->link : '';
        $this->toggled = $this->tab === 'campaigns' ? $this->toggled : [];
        // A segment's drawer opens over a paid source's segments alone: the tab says which of them it is.
        $this->segment = $this->tab === 'campaigns' && AnalyticsSource::paidSourceId((string) $this->source) !== null
            && (is_int($this->segment) || is_string($this->segment)) && preg_match('/^(new|[1-9]\d{0,17})$/', (string) $this->segment) === 1 ? (string) $this->segment : '';

        // The search, the drawer and the details are the Tracking links tab's alone.
        $this->q = $this->tab === 'links' && is_string($this->q) ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $this->q)), 0, 120) : '';
        $this->edit = $this->tab === 'links' && (is_int($this->edit) || is_string($this->edit)) && preg_match('/^(new|(copy-)?[1-9]\d{0,17})$/', (string) $this->edit) === 1 ? (string) $this->edit : '';
        $this->show = $this->tab === 'links' && $this->edit === '' && self::isId($this->show) ? (string) $this->show : '';

        // The two sources compared are the Site engagement tab's alone.
        $this->a = $this->tab === 'engagement' ? (AnalyticsSource::read($this->a) ?? '') : '';
        $this->b = $this->tab === 'engagement' ? (AnalyticsSource::read($this->b) ?? '') : '';

        // The cut of the cohorts is the Retention tab's alone.
        $this->dim = $this->tab === 'retention' ? RetentionTab::readDim($this->dim) : '';

        // What is looked up is the Journeys tab's alone, as typed — an ID or not — but in lower case.
        $this->who = $this->tab === 'journeys' && is_string($this->who) ? mb_substr(mb_strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $this->who))), 0, 80) : '';

        // The sorting and the open country are the Geography tab's alone.
        $this->gsort = $this->tab === 'geo' && is_string($this->gsort) && array_key_exists($this->gsort, GeographyTab::SORTS) ? $this->gsort : 'visitors';
        $this->open = $this->tab === 'geo' ? GeographyTab::readOpen($this->open) : '';

        // The game and its filters are the Games tab's alone.
        if ($this->tab === 'games') {
            $game = GamesTab::chosen($this->game);
            $this->game = $game === null ? '' : (string) $game;
            $this->settleGameAnalytics($game ?? 0);
        } else {
            $this->game = '';
            $this->clearGameAnalytics();
        }
    }
}
