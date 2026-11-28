<?php

namespace App\Filament\Pages;

use App\Actions\Traffic\ArchiveTrafficCampaignAction;
use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\ChangeTrafficCampaignStageAction;
use App\Actions\Traffic\ConnectTrafficApiAction;
use App\Actions\Traffic\EnterSpendAction;
use App\Actions\Traffic\ResendTrafficPostbackAction;
use App\Actions\Traffic\SaveTrafficCampaignAction;
use App\Actions\Traffic\SaveTrafficPricesAction;
use App\Actions\Traffic\SaveTrafficSettingsAction;
use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Actions\Traffic\SendTestPostbackAction;
use App\Enums\GeoEdition;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficEvent;
use App\Enums\TrafficSourceType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Concerns\ChoosesReportPeriod;
use App\Filament\Pages\TrafficSources\TrafficSourcesView;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\User;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Services\Traffic\Postbacks\PostbackAttempt;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Geo\Countries;
use App\Support\Traffic\Blocking\BlockRule;
use App\Support\Traffic\Presets\SourceDefaults;
use App\Support\Traffic\Reports\ReportPeriod;
use App\Support\Traffic\Reports\SiteZoneList;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Growth → Traffic sources (docs/admin/traffic-sources.md): where paid and
 * unpaid visits come from and what they bring — Report, Campaigns, Value,
 * Spend, Sources and Logs. Everything on screen is in the page's address;
 * what each tab reads comes from TrafficSourcesView once the page is in a
 * browser (`wire:init`), so asking for the page reads no visits. Every
 * change goes through an Action; the editors keep a draft until Save.
 */
final class TrafficSourcesPage extends Page
{
    use ChoosesReportPeriod;
    use WithFileUploads;

    public const array TABS = ['report' => 'Report', 'campaigns' => 'Campaigns', 'value' => 'Value', 'spend' => 'Spend', 'sources' => 'Sources', 'logs' => 'Logs'];

    /** The name the administrator's last choice of period is kept under (ReportPeriodMemory). */
    public const string PERIOD_MEMORY = 'traffic-sources';

    /** Every report page's presets: all time is every network day since the first visit, as far back as visits are kept. */
    public const array PERIODS = ReportPeriod::PRESETS;

    /** A ticked site or zone: its source, its level and its ID, up to 120 characters, as many as a ref holds in any script. */
    private const string SELECTION_KEY = '/^\d+:(site|zone):.{1,120}$/u';

    protected string $view = 'filament.pages.traffic-sources';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::GROWTH;

    protected static ?string $navigationLabel = 'Traffic sources';

    protected static ?string $title = 'Traffic sources';

    protected static ?string $slug = 'traffic-sources';

    // Last in Growth, after Games (1) and Experiments (2).
    protected static ?int $navigationSort = 4;

    #[Url(except: 'report')]
    public mixed $tab = 'report';

    /** A source's id, or every source. */
    #[Url(except: '')]
    public mixed $source = '';

    /** Paid or unpaid traffic: a network, a placement, unpaid, or all of it. */
    #[Url(except: '')]
    public mixed $type = '';

    /** A campaign's id, or every campaign. */
    #[Url(as: 'campaign', except: '')]
    public mixed $campaignId = '';

    /** A game's id, or every game. */
    #[Url(except: '')]
    public mixed $game = '';

    #[Url(except: '')]
    public mixed $device = '';

    /** A country's two-letter code, or every country. */
    #[Url(except: '')]
    public mixed $country = '';

    /** The report's view: a tree — by source, device, country, game or version — or the flat list of sites and zones. */
    #[Url(as: 'view', except: 'sources')]
    public mixed $reportView = 'sources';

    /** What Sign-ups and confirmation splits its sign-ups by: one of TrafficSourcesView::SIGNUP_DIMENSIONS. */
    #[Url(as: 'signups', except: 'source')]
    public mixed $signupsBy = 'source';

    /** @var list<string> the tree's rows opened, by their path; none until one is clicked */
    public array $open = [];

    /** @var list<string> the tree's levels shown in full rather than their ten biggest rows */
    public array $more = [];

    /** Sites & zones: one row per zone (a site where a network has no zones), or per site. */
    #[Url(as: 'list', except: 'zone')]
    public mixed $listBy = 'zone';

    #[Url(except: '')]
    public mixed $q = '';

    /** Sites & zones leaves out those under so many clicks. */
    #[Url(as: 'min', except: 0)]
    public mixed $minClicks = 0;

    /** Sites & zones keeps only those whose share in one column is above or below so many percent: the column, '' for none. */
    #[Url(as: 'share', except: '')]
    public mixed $shareColumn = '';

    #[Url(as: 'than', except: 'below')]
    public mixed $shareSide = 'below';

    /** The percent, '' while none is typed. */
    #[Url(as: 'pct', except: '')]
    public mixed $sharePercent = '';

    #[Url(as: 'seg', except: 'all')]
    public mixed $segment = 'all';

    #[Url(except: 'spend')]
    public mixed $sort = 'spend';

    #[Url(except: 'desc')]
    public mixed $dir = 'desc';

    /** @var list<string> the sites and zones ticked, as source:level:ref, kept across pages */
    public array $selected = [];

    /** The Worth blocking panel: the block rule, its thresholds and how many meet it. */
    public bool $blockRule = false;

    /** The source editor: a preset to start from (`new:popads`, `new:placement`) or a source's id. */
    #[Url(except: '')]
    public mixed $edit = '';

    /** The campaign panel: a campaign's id. */
    #[Url(except: '')]
    public mixed $camp = '';

    #[Url(except: 'postbacks')]
    public mixed $log = 'postbacks';

    /** Logs → Postbacks: every postback, or only those that failed. */
    #[Url(as: 'only', except: 'all')]
    public mixed $logFilter = 'all';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** Whether the page is in a browser: only then does a tab read its figures. */
    public bool $ready = false;

    /** The Add source panel. */
    public bool $adding = false;

    /** @var array{source: array<string, mixed>, rules: list<array<string, mixed>>} */
    public array $draft = ['source' => [], 'rules' => []];

    #[Locked]
    public ?int $draftLockVersion = null;

    /** @var array<string, mixed> */
    public array $campaignDraft = [];

    #[Locked]
    public ?int $campaignLockVersion = null;

    public string $checkLink = '';

    public string $linkGame = '';

    /** The source whose link Campaigns shows: a network or a banner, the first one to begin with. */
    public string $linkSource = '';

    /** The source Spend shows day by day and takes a corrected day or a file for. */
    public mixed $spendSource = '';

    /** @var list<int> the sources Spend shows by campaign */
    public array $spendOpen = [];

    /** @var array<int|string, string> each source’s target cost of an account, as typed on Value, by source id */
    public array $targets = [];

    /** Campaigns: those in a stage, the new ones, the archived, or every running one. */
    #[Url(except: 'all')]
    public mixed $stage = 'all';

    /** @var array{day: string, amount: string} */
    public array $spendEntry = ['day' => '', 'amount' => ''];

    /** @var array<int|string, TemporaryUploadedFile> a spend file uploading, by the source it was chosen for */
    public array $spendFiles = [];

    /** @var array{account_price: string, post_bonus: string, returned_bonus: string, login_price: string} */
    public array $prices = ['account_price' => '', 'post_bonus' => '', 'returned_bonus' => '', 'login_price' => ''];

    /** @var array<int|string, string> each paid source's own account price, as typed on Value by source id; blank counts the site's */
    public array $accountPrices = [];

    /** @var list<array{key: string, name: string, percent: int|string, countries: string}> */
    public array $groups = [];

    #[Locked]
    public int $pricesFrom = 0;

    /** What the administrator typed to connect a network's API; cleared once used, never shown. */
    public string $secret = '';

    /** @var array<string, mixed> */
    public array $settings = [];

    #[Locked]
    public int $settingsLock = 1;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-traffic-sources');
    }

    public function mount(): void
    {
        $this->openPeriod();
        $this->normalize();
        // Campaigns' link starts on the first network, or the first banner, and the first game.
        $this->linkSource = (string) (TrafficSource::query()->where('type', TrafficSourceType::Network)->orderBy('name')->value('id')
            ?? TrafficSource::query()->where('type', TrafficSourceType::Placement)->orderBy('name')->value('id') ?? '');
        $this->linkGame = (string) (Game::query()->orderBy('title')->value('id') ?? '');
        $this->drawTargets();
        $firstPaid = TrafficSource::query()->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->orderBy('name')->value('id');
        $this->spendSource = is_numeric($this->spendSource) ? (string) (int) $this->spendSource : (string) ($firstPaid ?? '');
        $prices = TrafficPriceSet::current();
        $this->drawPriceFields($prices);
        $this->drawGroups($prices);
        $this->drawSettings();

        if ($this->edit !== '') {
            $this->openEditor((string) $this->edit);
        }

        if ($this->camp !== '') {
            $this->openCampaign($this->camp);
        }
    }

    public function load(): void
    {
        $this->ready = true;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'period', 'from', 'to', 'source', 'type', 'campaignId', 'game', 'device', 'country', 'log', 'logFilter', 'reportView', 'listBy', 'q', 'minClicks', 'shareColumn', 'shareSide', 'sharePercent', 'segment'], true)) {
            $this->pageNumber = 1;
        }

        if (in_array($property, ['period', 'from', 'to', 'source', 'type', 'campaignId', 'game', 'device', 'country', 'reportView', 'listBy'], true)) {
            $this->selected = [];
        }

        if ($property === 'source') {
            $this->campaignId = '';
        }

        // Record site visits counts only with Record visits: switching that off switches it off on the card at once.
        if ($property === 'settings.capture_enabled' && ! (bool) ($this->settings['capture_enabled'] ?? false)) {
            $this->settings['site_capture_enabled'] = false;
        }

        $this->normalize();
    }

    /** Visits are kept so long; no range may start before. */
    protected function keptDays(): int
    {
        return (int) config('traffic.retention_days.visits');
    }

    /** A new period starts the list over, and nothing ticked stays ticked. */
    protected function periodChanged(): void
    {
        $this->pageNumber = 1;
        $this->selected = [];
    }

    public function showTab(mixed $tab): void
    {
        $this->tab = $tab;
        $this->pageNumber = 1;
        $this->normalize();
    }

    /**
     * Opens Report on one source: a banner's figures from its row in
     * Campaigns, on that source alone — no campaign, type of traffic or
     * sites ticked kept from the filters before.
     */
    public function reportFor(mixed $sourceId): void
    {
        $this->source = is_numeric($sourceId) ? (string) (int) $sourceId : '';
        $this->campaignId = '';
        $this->type = '';
        $this->selected = [];
        $this->tab = 'report';
        $this->pageNumber = 1;
        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /** Opens a row of the tree, or closes it. */
    public function toggleNode(mixed $key): void
    {
        if (! is_string($key) || mb_strlen($key) > 300) {
            return;
        }

        $this->open = in_array($key, $this->open, true)
            ? array_values(array_filter($this->open, fn (string $open): bool => $open !== $key && ! str_starts_with($open, $key.'|')))
            : [...$this->open, $key];
    }

    /** Shows every row of a level of the tree, not just its ten biggest. */
    public function showMore(mixed $key): void
    {
        if (is_string($key) && mb_strlen($key) <= 300 && ! in_array($key, $this->more, true)) {
            $this->more[] = $key;
        }
    }

    /**
     * Opens a source's sites or zones as the flat list, from the “more” row
     * of the tree — or searched for one of them, from its row in Sign-ups
     * and confirmation.
     */
    public function listSites(mixed $sourceId, mixed $level, mixed $ref = ''): void
    {
        $this->reportView = 'sites';
        $this->source = is_numeric($sourceId) ? (string) (int) $sourceId : $this->source;
        $this->listBy = $level === 'site' ? 'site' : 'zone';
        $this->segment = 'all';
        $this->q = is_string($ref) ? $ref : '';
        // Looking up one site or zone: the row asked for shows, however few clicks it has and whatever its shares.
        if ($this->q !== '') {
            $this->minClicks = 0;
            $this->shareColumn = '';
            $this->sharePercent = '';
        }
        $this->pageNumber = 1;
        $this->selected = [];
        $this->normalize();
    }

    public function sortBy(mixed $column): void
    {
        if (! is_string($column) || ! in_array($column, SiteZoneList::SORTS, true)) {
            return;
        }

        $this->dir = $this->sort === $column && $this->dir === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->pageNumber = 1;
    }

    /** Ticks a site or zone, or unticks it. */
    public function toggleSelected(mixed $key): void
    {
        if (! is_string($key) || preg_match(self::SELECTION_KEY, $key) !== 1) {
            return;
        }

        $this->selected = in_array($key, $this->selected, true)
            ? array_values(array_diff($this->selected, [$key]))
            : [...$this->selected, $key];
    }

    /** Ticks every row of the page, or unticks them all when each is ticked already. */
    public function togglePage(): void
    {
        $page = $this->siteList()['selectable'];
        $this->selected = array_diff($page, $this->selected) === []
            ? array_values(array_diff($this->selected, $page))
            : array_values(array_unique([...$this->selected, ...$page]));
    }

    /** Ticks every site or zone the segment, search and threshold match, on every page. */
    public function selectMatching(): void
    {
        $this->selected = $this->siteList()['matchingSelectable'];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Blocks the sites and zones ticked: through each network's API where it can, else marked by hand. */
    public function blockSelected(): void
    {
        $byHand = 0;
        $queued = 0;

        foreach ($this->selectedRefs() as ['source' => $source, 'level' => $level, 'refs' => $refs]) {
            $viaApi = app(BlockTrafficRefsAction::class)->canBlockViaApi($source, $level);

            try {
                // Those blocked already, and left as they are, aren't counted.
                $changed = app(BlockTrafficRefsAction::class)->block($this->admin(), $source, $level, $refs, null, $viaApi);
            } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
                $this->stopPartWay($queued + $byHand, $exception);

                return;
            }

            $viaApi ? $queued += $changed : $byHand += $changed;
            $this->untick($source, $level, $refs);
        }

        $this->selected = [];
        $this->toast(trim(($queued > 0 ? $queued.' queued for the network. ' : '').($byHand > 0 ? $byHand.' marked as blocked: add them to the network’s list — Copy IDs gives them to you.' : '')) ?: 'Nothing to block: those are blocked already.');
    }

    /** Unblocks the sites and zones ticked. */
    public function unblockSelected(): void
    {
        $count = 0;

        foreach ($this->selectedRefs() as ['source' => $source, 'level' => $level, 'refs' => $refs]) {
            $done = [];

            foreach (TrafficBlock::query()->where('source_id', $source->id)->where('level', $level)->whereIn('ref', $refs)->get() as $block) {
                try {
                    app(BlockTrafficRefsAction::class)->unblock($this->admin(), $block);
                } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
                    $this->untick($source, $level, $done);
                    $this->stopPartWay($count, $exception);

                    return;
                }

                $count++;
                $done[] = (string) $block->ref;
            }

            // The batch's every ref is done: unblocked, or with no block to unblock.
            $this->untick($source, $level, $refs);
        }

        $this->selected = [];
        $this->toast($count.' unblocked');
    }

    /** Sends the blocks ticked to the network again, to every campaign each is for: those whose network's API can't block them are left as they are. */
    public function pushSelected(): void
    {
        $count = 0;
        $skipped = 0;

        foreach ($this->selectedRefs() as ['source' => $source, 'level' => $level, 'refs' => $refs]) {
            if (! app(BlockTrafficRefsAction::class)->canBlockViaApi($source, $level)) {
                $skipped += count($refs);

                continue;
            }

            try {
                $count += app(BlockTrafficRefsAction::class)->push($this->admin(), $source, $level, $refs);
            } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
                $this->stopPartWay($count, $exception);

                return;
            }

            $this->untick($source, $level, $refs);
        }

        // Those whose network's API can't send them stay ticked, for Copy IDs.
        $this->toast(trim(($count > 0 ? $count.' queued for the network again. ' : '').($skipped > 0 ? $skipped.' can’t go through their network’s API: add them to its list by hand — they are still ticked for Copy IDs.' : '')) ?: 'Nothing to send: those are on their way already.', $count === 0 && $skipped > 0 ? 'error' : 'success');
    }

    /**
     * Unticks the sites or zones of a batch that went through, so a batch
     * that fails after it leaves ticked only what is still to do.
     *
     * @param  list<string>  $refs
     */
    private function untick(TrafficSource $source, TrafficBlockLevel $level, array $refs): void
    {
        $done = array_flip(array_map(fn (string $ref): string => $source->id.':'.$level->value.':'.$ref, $refs));
        $this->selected = array_values(array_filter($this->selected, fn (string $key): bool => ! isset($done[$key])));
    }

    /** Says why a batch was refused, after how many went through, leaving the rest ticked to try again. */
    private function stopPartWay(int $done, CannotManageTrafficException|RateLimitExceededException $exception): void
    {
        $this->toast(($done > 0 ? $done.' went through; the rest are still ticked. ' : '').$exception->getMessage(), 'error');
    }

    public function toggleBlockRule(): void
    {
        $this->blockRule = ! $this->blockRule;
    }

    /** Shows the sites and zones the block rule picks out. */
    public function showWorthBlocking(): void
    {
        $this->reportView = 'sites';
        $this->segment = 'block';
        $this->sort = 'spend';
        $this->dir = 'desc';
        $this->q = '';
        $this->pageNumber = 1;
        $this->blockRule = false;
    }

    /**
     * The sites and zones ticked, grouped by source and level, each source
     * read once, each group split into batches of as many as the actions take
     * at a time (MOST_REFS): Select all ticks every row that matches, however
     * many.
     *
     * @return list<array{source: TrafficSource, level: TrafficBlockLevel, refs: list<string>}>
     */
    private function selectedRefs(): array
    {
        $groups = [];

        foreach ($this->selected as $key) {
            [$sourceId, $level, $ref] = array_pad(explode(':', $key, 3), 3, '');
            $groups[$sourceId.':'.$level]['refs'][] = $ref;
            $groups[$sourceId.':'.$level]['source'] = (int) $sourceId;
            $groups[$sourceId.':'.$level]['level'] = $level;
        }

        $sources = TrafficSource::query()->whereKey(array_column($groups, 'source'))->get()->keyBy('id');
        $out = [];

        foreach ($groups as $group) {
            $source = $sources->get($group['source']);
            $level = TrafficBlockLevel::tryFrom($group['level']);

            if ($source === null || $level === null) {
                continue;
            }

            foreach (array_chunk(array_values(array_unique($group['refs'])), BlockTrafficRefsAction::MOST_REFS) as $refs) {
                $out[] = ['source' => $source, 'level' => $level, 'refs' => $refs];
            }
        }

        return $out;
    }

    /** @return array{selectable: list<string>, matchingSelectable: list<string>} */
    private function siteList(): array
    {
        return app(TrafficSourcesView::class)->siteSelection($this, $this->filter());
    }

    // Sources --------------------------------------------------------------

    #[On('add-traffic-source')]
    public function startAdding(): void
    {
        $this->adding = true;
    }

    public function closeAdding(): void
    {
        $this->adding = false;
    }

    public function openEditor(string $what): void
    {
        $this->adding = false;
        $this->secret = '';
        $catalog = app(TrafficPresetCatalog::class);

        if (str_starts_with($what, 'new:')) {
            $key = substr($what, 4);
            $taken = TrafficSource::query()->pluck('link_key')->all();
            $preset = $catalog->find($key);
            $defaults = $key === 'placement' || $preset === null
                ? SourceDefaults::forPlacement()
                : SourceDefaults::forPreset($key, $preset, $catalog->connector($preset['connector'] ?? null) !== null, $taken);
            $this->draft = ['source' => $defaults['source'], 'rules' => $defaults['rules']];
            $this->draftLockVersion = null;
            $this->edit = $what;

            return;
        }

        $source = is_numeric($what) ? TrafficSource::query()->with('postbackRules')->find((int) $what) : null;

        if ($source === null) {
            $this->edit = '';

            return;
        }

        $this->draft = TrafficSourcesView::draftOf($source);
        $this->draftLockVersion = $source->lock_version;
        $this->edit = (string) $source->id;
    }

    public function closeEditor(): void
    {
        $this->edit = '';
        $this->draft = ['source' => [], 'rules' => []];
        $this->secret = '';
        $this->resetErrorBag();
    }

    public function addParam(): void
    {
        $this->draft['source']['link_params'][] = ['kind' => 'campaign', 'label' => '', 'param' => '', 'token' => '', 'note' => ''];
    }

    public function removeParam(mixed $index): void
    {
        if (is_numeric($index)) {
            array_splice($this->draft['source']['link_params'], (int) $index, 1);
        }
    }

    public function addRule(): void
    {
        $this->draft['rules'][] = ['id' => null, 'event' => TrafficEvent::Goal->value, 'goal_code' => '', 'needs_event' => '', 'url_template' => '', 'enabled' => false];
    }

    /**
     * A rule turned to a settled visit asks, to begin with, for one that
     * started the game: a network that counts conversions learns nothing when
     * every visit that opened the page is one.
     */
    public function updatedDraft(mixed $value, string $key): void
    {
        if (preg_match('/^rules\.(\d+)\.event$/', $key, $match) === 1 && $value === TrafficEvent::Settled->value && ($this->draft['rules'][(int) $match[1]]['needs_event'] ?? '') === '') {
            $this->draft['rules'][(int) $match[1]]['needs_event'] = TrafficEvent::Started->value;
        }
    }

    public function removeRule(mixed $index): void
    {
        if (is_numeric($index)) {
            array_splice($this->draft['rules'], (int) $index, 1);
        }
    }

    public function saveSource(): void
    {
        $id = is_numeric($this->edit) ? (int) $this->edit : null;

        try {
            $source = app(SaveTrafficSourceAction::class)->handle($this->admin(), $id, $this->draft['source'], $this->draft['rules'], $this->draftLockVersion);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast('Some fields need fixing before the source can be saved.', 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->openEditor((string) $source->id);
        $this->toast('Source saved');
    }

    public function testRule(mixed $index): void
    {
        $rule = is_numeric($index) ? ($this->draft['rules'][(int) $index] ?? null) : null;

        if ($rule === null) {
            return;
        }

        try {
            $attempt = app(SendTestPostbackAction::class)->handle($this->admin(), (string) $rule['url_template'], TrafficEvent::from((string) $rule['event']), (string) ($rule['goal_code'] ?? ''), $this->draft['source']['response_rules'] ?? null);
        } catch (ValidationException $exception) {
            $this->toast(collect($exception->errors())->flatten()->first() ?? 'The address can’t be sent.', 'error');

            return;
        } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast(self::testOutcome($attempt, app(TrafficPresetCatalog::class)->find($this->draft['source']['preset_key'] ?? null)), $attempt->outcome->value === 'ok' ? 'success' : 'error');
    }

    /**
     * What Send test came to, with what the network said, as far as the log
     * keeps it (its body or the header a rule reads), and, when the network
     * refused it, the preset's word on a network that refuses every made-up
     * click.
     *
     * @param  array<string, mixed>|null  $preset
     */
    private static function testOutcome(PostbackAttempt $attempt, ?array $preset): string
    {
        $said = trim((string) $attempt->excerpt);
        $note = $attempt->outcome->value === 'rejected' ? trim((string) ($preset['postback']['test_note'] ?? '')) : '';

        return 'Test postback: '.$attempt->outcome->value
            .($attempt->httpStatus !== null ? ' · HTTP '.$attempt->httpStatus : '')
            .($attempt->latencyMs !== null ? ' · '.$attempt->latencyMs.' ms' : '')
            .($said !== '' ? ' · '.Str::limit($said, 120) : '')
            .($note !== '' ? '. '.$note : '');
    }

    public function connectApi(): void
    {
        $source = $this->editedSource();

        if ($source === null) {
            return;
        }

        try {
            app(ConnectTrafficApiAction::class)->connect($this->admin(), $source, [$source->connector?->value === 'exoclick' ? 'api_token' : 'api_key' => $this->secret]);
        } catch (CannotManageTrafficException $exception) {
            $this->secret = '';
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->secret = '';
        $this->toast('Connected. Spend and campaigns are read within a minute.');
    }

    public function disconnectApi(): void
    {
        $source = $this->editedSource();

        if ($source !== null) {
            $this->attempt(fn () => app(ConnectTrafficApiAction::class)->disconnect($this->admin(), $source), 'Disconnected; the key is forgotten.');
            $this->openEditor((string) $source->id);
        }
    }

    /** Shows a source by campaign on Spend, or folds it back. */
    public function toggleSpendSource(mixed $sourceId): void
    {
        if (is_numeric($sourceId)) {
            $this->spendOpen = in_array((int) $sourceId, $this->spendOpen, true)
                ? array_values(array_diff($this->spendOpen, [(int) $sourceId]))
                : [...$this->spendOpen, (int) $sourceId];
        }
    }

    /** Asks a source's network for its figures at the next sync, from its row on Spend. */
    public function syncSource(mixed $sourceId): void
    {
        $source = is_numeric($sourceId) ? TrafficSource::query()->find((int) $sourceId) : null;

        if ($source !== null) {
            $this->attempt(fn () => app(ConnectTrafficApiAction::class)->syncNow($this->admin(), $source), 'Syncing within a minute.');
        }
    }

    public function syncNow(): void
    {
        $source = $this->editedSource();

        if ($source !== null) {
            $this->attempt(fn () => app(ConnectTrafficApiAction::class)->syncNow($this->admin(), $source), 'Syncing within a minute.');
        }
    }

    public function saveSettings(): void
    {
        try {
            app(SaveTrafficSettingsAction::class)->handle($this->admin(), $this->settings, $this->settingsLock);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');
            $this->drawSettings();

            return;
        }

        $this->drawSettings();
        $this->toast('Tracking saved');
    }

    public function refreshGeo(): void
    {
        $this->attempt(fn () => app(SaveTrafficSettingsAction::class)->refreshGeo($this->admin()), 'The IP database is checked within a minute.');
    }

    // Campaigns ------------------------------------------------------------

    public function openCampaign(mixed $id): void
    {
        $campaign = is_numeric($id) ? TrafficCampaign::query()->find((int) $id) : null;

        if ($campaign === null) {
            $this->camp = '';

            return;
        }

        $this->camp = (string) $campaign->id;
        $this->campaignLockVersion = $campaign->lock_version;
        $this->campaignDraft = [
            'name' => (string) $campaign->name,
            'game_id' => $campaign->game_id === null ? '' : (string) $campaign->game_id,
            'target_cpa' => (string) $campaign->target_cpa,
            'daily_budget' => (string) $campaign->daily_budget,
            'spend_mode' => $campaign->spend_mode->value ?? '',
            'bid_model' => (string) ($campaign->bid_model ?? 'click'),
            'bid_mobile' => (string) $campaign->bid_mobile,
            'bid_desktop' => (string) $campaign->bid_desktop,
            'price_from' => now()->toDateString(),
        ];

        // Targeting is typed in only where the network doesn't report it; a reported one stays as synced.
        if (! TrafficApiConnection::query()->where('source_id', $campaign->source_id)->where('status', TrafficConnectionStatus::Connected)->exists()) {
            $this->campaignDraft += ['countries' => $campaign->countries ?? [], 'devices' => $campaign->devices ?? [], 'format' => (string) $campaign->format];
        }
    }

    /** Puts a country in the open campaign's typed-in targeting, or takes it out. */
    public function toggleCampaignCountry(mixed $code): void
    {
        if (! is_string($code) || ! array_key_exists('countries', $this->campaignDraft) || ! array_key_exists(strtoupper($code), Countries::all())) {
            return;
        }

        $code = strtoupper($code);
        $countries = (array) $this->campaignDraft['countries'];
        $this->campaignDraft['countries'] = in_array($code, $countries, true) ? array_values(array_diff($countries, [$code])) : [...$countries, $code];
    }

    public function toggleCampaignDevice(mixed $device): void
    {
        if (! in_array($device, ['mobile', 'desktop'], true) || ! array_key_exists('devices', $this->campaignDraft)) {
            return;
        }

        $devices = (array) $this->campaignDraft['devices'];
        $this->campaignDraft['devices'] = in_array($device, $devices, true) ? array_values(array_diff($devices, [$device])) : [...$devices, $device];
    }

    /** Opens Report on the open campaign: its source, its campaign. */
    public function campaignReport(): void
    {
        $campaign = is_numeric($this->camp) ? TrafficCampaign::query()->find((int) $this->camp) : null;

        if ($campaign !== null) {
            $this->source = (string) $campaign->source_id;
            $this->campaignId = (string) $campaign->id;
            $this->tab = 'report';
            $this->closeCampaign();
            $this->normalize();
        }
    }

    public function closeCampaign(): void
    {
        $this->camp = '';
        $this->campaignDraft = [];
        $this->resetErrorBag();
    }

    public function saveCampaign(): void
    {
        $campaign = TrafficCampaign::query()->find((int) $this->camp);

        if ($campaign === null || $this->campaignLockVersion === null) {
            return;
        }

        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->campaignDraft);

        try {
            app(SaveTrafficCampaignAction::class)->handle($this->admin(), $campaign, $input, $this->campaignLockVersion);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            // Said aloud too: a field can be far down the panel from Save.
            $this->toast('Some fields need fixing before the campaign can be saved.', 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->openCampaign($campaign->id);
        $this->toast('Campaign saved');
    }

    public function setStage(mixed $stage): void
    {
        $campaign = TrafficCampaign::query()->find((int) $this->camp);
        $stage = is_string($stage) ? TrafficCampaignStage::tryFrom($stage) : null;

        if ($campaign !== null && $stage !== null) {
            $this->changeCampaign(fn (): TrafficCampaign => app(ChangeTrafficCampaignStageAction::class)->handle($this->admin(), $campaign, $stage), 'Stage: '.ucfirst($stage->value));
        }
    }

    public function archiveCampaign(bool $restore = false): void
    {
        $campaign = TrafficCampaign::query()->find((int) $this->camp);

        if ($campaign !== null) {
            $this->changeCampaign(fn (): TrafficCampaign => $restore
                ? app(ArchiveTrafficCampaignAction::class)->restore($this->admin(), $campaign)
                : app(ArchiveTrafficCampaignAction::class)->archive($this->admin(), $campaign), $restore ? 'Campaign restored' : 'Campaign archived');
        }
    }

    /**
     * A stage set, an archive or a restore of the open campaign, its draft
     * kept as typed: the version the change moved the campaign to becomes
     * the draft's, so Save still goes through — unless someone else saved the
     * campaign after it was opened, which Save must then say. Whether they
     * did is told by the version the change found under its lock, not by an
     * earlier read their save may have landed after. A refusal keeps the
     * draft and its version.
     *
     * @param  callable(): TrafficCampaign  $change
     */
    private function changeCampaign(callable $change, string $done): void
    {
        try {
            $changed = $change();
        } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $seen = $changed->wasChanged('lock_version') ? (int) $changed->getPrevious()['lock_version'] : $changed->lock_version;

        if ($seen === $this->campaignLockVersion) {
            $this->campaignLockVersion = $changed->lock_version;
        }

        $this->toast($done);
    }

    // Report: blocking -----------------------------------------------------

    public function block(mixed $sourceId, mixed $level, mixed $ref, mixed $viaApi = false): void
    {
        $source = is_numeric($sourceId) ? TrafficSource::query()->find((int) $sourceId) : null;
        $level = is_string($level) ? TrafficBlockLevel::tryFrom($level) : null;

        if ($source !== null && $level !== null && is_scalar($ref)) {
            $this->attempt(fn () => app(BlockTrafficRefsAction::class)->block($this->admin(), $source, $level, [(string) $ref], null, (bool) $viaApi), $viaApi ? 'Queued for the network' : 'Marked as blocked; add the ID to the network’s list');
        }
    }

    public function unblock(mixed $blockId): void
    {
        $block = is_numeric($blockId) ? TrafficBlock::query()->find((int) $blockId) : null;

        if ($block !== null) {
            $this->attempt(fn () => app(BlockTrafficRefsAction::class)->unblock($this->admin(), $block), 'Unblocked');
        }
    }

    // Spend ----------------------------------------------------------------

    public function enterSpend(): void
    {
        $source = is_numeric($this->spendSource) ? TrafficSource::query()->find((int) $this->spendSource) : null;

        if ($source === null) {
            $this->toast('Choose a source first.', 'error');

            return;
        }

        try {
            app(EnterSpendAction::class)->day($this->admin(), $source, $this->spendEntry['day'], $this->spendEntry['amount'] === '' ? null : $this->spendEntry['amount']);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->spendEntry = ['day' => '', 'amount' => ''];
        $this->toast('Spend saved; it is shared over the day’s visits within a minute.');
    }

    /**
     * A file chosen on Spend is taken as soon as it has uploaded, into the
     * source it was chosen for: the key it uploads under. Another source may
     * be on screen by the time a slow upload ends, and its days are not the
     * file's to replace.
     */
    public function updatedSpendFiles(mixed $file, ?string $key = null): void
    {
        if ($key === null || ! ctype_digit($key)) {
            $this->spendFiles = [];

            return;
        }

        unset($this->spendFiles[$key]);
        $source = TrafficSource::query()->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->find((int) $key);

        if ($source === null || ! $file instanceof TemporaryUploadedFile) {
            $this->toast('Choose a source and a file first.', 'error');

            return;
        }

        try {
            $import = app(EnterSpendAction::class)->file($this->admin(), $source, $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()));
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast($import->fact_rows.' figures imported'.($import->skipped_rows > 0 ? ', '.$import->skipped_rows.' rows of other campaigns left out' : ''));
    }

    // Value ----------------------------------------------------------------

    /** Saves the site's prices with each paid source's own account price as typed beside it, as one new set. */
    public function savePrices(): void
    {
        $sourcePrices = array_filter($this->accountPrices, fn (mixed $price, int|string $sourceId): bool => ctype_digit((string) $sourceId), ARRAY_FILTER_USE_BOTH);

        try {
            $saved = app(SaveTrafficPricesAction::class)->prices($this->admin(), $this->prices, $this->pricesFrom, $sourcePrices);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            // Said aloud too: a source's price can be wrong far from the button that saved it.
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The country groups stay as typed: the set saved carried over the ones they were drawn from.
        $this->drawPriceFields($saved);
        $this->toast('Prices saved. Visits from now on count by them; earlier ones keep theirs.');
    }

    public function saveGroups(): void
    {
        $groups = array_map(fn (array $group): array => [...$group, 'countries' => array_values(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), explode(',', (string) $group['countries']))))], $this->groups);

        try {
            $saved = app(SaveTrafficPricesAction::class)->groups($this->admin(), $groups, $this->pricesFrom);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        // The prices stay as typed: the set saved carried over the ones they were drawn from.
        $this->drawGroups($saved);
        $this->toast('Country groups saved. The chances are worked out again within five minutes.');
    }

    /** Saves the target cost of an account typed for one source, the rest of the source as it is. */
    public function saveTarget(mixed $sourceId): void
    {
        $source = is_numeric($sourceId) ? TrafficSource::query()->with('postbackRules')->find((int) $sourceId) : null;

        if ($source === null) {
            return;
        }

        $draft = TrafficSourcesView::draftOf($source);
        $target = trim($this->targets[(string) $source->id] ?? '');

        try {
            app(SaveTrafficSourceAction::class)->handle($this->admin(), (int) $source->id, [...$draft['source'], 'target_cpa' => $target === '' ? null : $target], $draft['rules'], $source->lock_version);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->drawTargets();
        $this->toast($target === '' ? $source->name.' has no target now' : $source->name.'’s target saved');
    }

    /** Puts a country in a group, out of any other it was in. */
    public function addCountry(mixed $index, mixed $code): void
    {
        if (! is_numeric($index) || ! isset($this->groups[(int) $index]) || ! is_string($code) || ! array_key_exists(strtoupper($code), Countries::all())) {
            return;
        }

        $code = strtoupper($code);

        foreach ($this->groups as $at => $group) {
            $this->groups[$at]['countries'] = implode(', ', array_values(array_diff(self::codes($group['countries']), [$code])));
        }

        $this->groups[(int) $index]['countries'] = implode(', ', [...self::codes($this->groups[(int) $index]['countries']), $code]);
    }

    public function removeCountry(mixed $index, mixed $code): void
    {
        if (is_numeric($index) && isset($this->groups[(int) $index]) && is_string($code)) {
            $this->groups[(int) $index]['countries'] = implode(', ', array_values(array_diff(self::codes($this->groups[(int) $index]['countries']), [strtoupper($code)])));
        }
    }

    /** @return list<string> */
    private static function codes(mixed $countries): array
    {
        return array_values(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), explode(',', (string) $countries))));
    }

    private function drawTargets(): void
    {
        $this->targets = TrafficSource::query()->pluck('target_cpa', 'id')->mapWithKeys(fn (mixed $target, mixed $id): array => [(string) $id => $target === null ? '' : rtrim(rtrim((string) $target, '0'), '.')])->all();
    }

    public function addGroup(): void
    {
        // The first key no group has: counting the groups again would repeat one removed and added back.
        $taken = array_column($this->groups, 'key');
        $number = count($this->groups);

        while (in_array('group'.$number, $taken, true)) {
            $number++;
        }

        array_splice($this->groups, max(0, count($this->groups) - 1), 0, [['key' => 'group'.$number, 'name' => '', 'percent' => 100, 'countries' => '']]);
    }

    public function removeGroup(mixed $index): void
    {
        if (is_numeric($index) && count($this->groups) > 1 && (int) $index < count($this->groups) - 1) {
            array_splice($this->groups, (int) $index, 1);
        }
    }

    // Logs -----------------------------------------------------------------

    public function resend(mixed $postbackId): void
    {
        $postback = is_numeric($postbackId) ? TrafficPostback::query()->find((int) $postbackId) : null;

        if ($postback === null) {
            return;
        }

        try {
            $attempt = app(ResendTrafficPostbackAction::class)->handle($this->admin(), $postback);
        } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast('Sent again: '.$attempt->outcome->value, $attempt->outcome->value === 'ok' ? 'success' : 'error');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $filter = $this->filter();
        $data = app(TrafficSourcesView::class)->data($this, $filter);

        return [...$data, 'periodPicker' => $this->periodPicker($data['periodDays'], [$filter->fromDay, $filter->toDay])];
    }

    public function filter(): TrafficReportFilter
    {
        $period = $this->reportPeriod();
        // A preset's network days up to today's; all time from the first visit's, no further back than visits are kept.
        [$first, $last] = $period->fromDay !== null && $period->toDay !== null
            ? [$period->fromDay, $period->toDay]
            : array_map(
                fn (CarbonImmutable $day): string => $day->toDateString(),
                ReportPeriod::presetDays($period->preset, CarbonImmutable::now('UTC'), $this->keptDays(), fn (): ?string => app(AnalyticsVisitsQuery::class)->firstDay()),
            );
        $filter = new TrafficReportFilter($first, $last);
        $where = array_filter([
            'source_id' => is_numeric($this->source) ? (int) $this->source : null,
            'channel' => in_array($this->type, ['network', 'placement'], true) ? $this->type : null,
            'campaign_id' => is_numeric($this->campaignId) ? (int) $this->campaignId : null,
            'game_id' => is_numeric($this->game) ? (int) $this->game : null,
            'device' => in_array($this->device, ['mobile', 'desktop'], true) ? $this->device : null,
            'country' => is_string($this->country) && Countries::has($this->country) ? $this->country : null,
        ], fn (mixed $value): bool => $value !== null);

        // Unpaid traffic is the traffic of no source.
        if ($this->type === 'unpaid' && ! isset($where['source_id'])) {
            $where['source_id'] = null;
        }

        return new TrafficReportFilter($filter->fromDay, $filter->toDay, $where);
    }

    private function normalize(): void
    {
        $this->tab = is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'report';
        $this->settlePeriod();
        $this->source = is_numeric($this->source) ? (string) (int) $this->source : '';
        $this->type = in_array($this->type, ['network', 'placement', 'unpaid'], true) ? $this->type : '';
        $this->campaignId = is_numeric($this->campaignId) ? (string) (int) $this->campaignId : '';
        $this->game = is_numeric($this->game) ? (string) (int) $this->game : '';
        $this->device = in_array($this->device, ['mobile', 'desktop'], true) ? $this->device : '';
        $this->country = is_string($this->country) && Countries::has(strtoupper($this->country)) ? strtoupper($this->country) : '';
        $this->reportView = is_string($this->reportView) && array_key_exists($this->reportView, TrafficSourcesView::VIEWS) ? $this->reportView : 'sources';
        $this->signupsBy = is_string($this->signupsBy) && array_key_exists($this->signupsBy, TrafficSourcesView::SIGNUP_DIMENSIONS) ? $this->signupsBy : 'source';
        $this->listBy = $this->listBy === 'site' ? 'site' : 'zone';
        $this->stage = in_array($this->stage, ['test', 'optimise', 'scale', 'paused', 'new', 'archived'], true) ? $this->stage : 'all';
        $this->spendSource = is_numeric($this->spendSource) ? (string) (int) $this->spendSource : '';
        $this->spendOpen = array_values(array_map('intval', array_filter($this->spendOpen, 'is_numeric')));
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 80) : '';
        $this->minClicks = is_numeric($this->minClicks) ? max(0, min(100000, (int) $this->minClicks)) : 0;
        $this->shareColumn = is_string($this->shareColumn) && array_key_exists($this->shareColumn, SiteZoneList::SHARES) ? $this->shareColumn : '';
        $this->shareSide = $this->shareSide === 'above' ? 'above' : 'below';
        $this->sharePercent = is_numeric($this->sharePercent) ? (string) round(max(0.0, min(100.0, (float) $this->sharePercent)), 2) : '';
        $this->segment = is_string($this->segment) && array_key_exists($this->segment, SiteZoneList::SEGMENTS) ? $this->segment : 'all';
        $this->sort = is_string($this->sort) && in_array($this->sort, SiteZoneList::SORTS, true) ? $this->sort : 'spend';
        $this->dir = $this->dir === 'asc' ? 'asc' : 'desc';
        $this->open = array_values(array_filter($this->open, 'is_string'));
        $this->more = array_values(array_filter($this->more, 'is_string'));
        // Every tick kept, however many Select all made: the actions send them a batch at a time (selectedRefs).
        $this->selected = array_values(array_unique(array_filter(array_filter($this->selected, 'is_string'), fn (string $key): bool => preg_match(self::SELECTION_KEY, $key) === 1)));
        $this->log = is_string($this->log) && in_array($this->log, ['clicks', 'milestones', 'postbacks', 'blocks'], true) ? $this->log : 'postbacks';
        $this->logFilter = $this->logFilter === 'failed' ? 'failed' : 'all';
        $this->pageNumber = is_numeric($this->pageNumber) ? max(1, (int) $this->pageNumber) : 1;
        $this->edit = is_string($this->edit) || is_int($this->edit) ? (string) $this->edit : '';
        $this->camp = is_numeric($this->camp) ? (string) (int) $this->camp : '';
    }

    /** Value's prices and each paid source's own account price as a set has them, the set the next save of either draft is drawn from. */
    private function drawPriceFields(TrafficPriceSet $set): void
    {
        $this->pricesFrom = $set->id;
        // Prices read as money: two decimals, more only where a price has them.
        $money = fn (string $amount): string => number_format((float) $amount, max(2, strlen(rtrim((string) substr((string) strrchr($amount, '.'), 1), '0'))), '.', '');
        $this->prices = ['account_price' => $money((string) $set->account_price), 'post_bonus' => $money((string) $set->post_bonus), 'returned_bonus' => $money((string) $set->returned_bonus), 'login_price' => $money((string) $set->login_price)];
        $this->accountPrices = array_map($money, $set->sourceAccountPrices());
    }

    /** Value's country groups as a set has them, the set the next save of either draft is drawn from. */
    private function drawGroups(TrafficPriceSet $set): void
    {
        $this->pricesFrom = $set->id;
        $this->groups = array_map(fn (array $group): array => ['key' => $group['key'], 'name' => $group['name'], 'percent' => $group['percent'], 'countries' => implode(', ', $group['countries'])], $set->country_groups);
    }

    private function drawSettings(): void
    {
        $settings = TrafficSetting::current();
        $this->settingsLock = (int) ($settings->lock_version ?? 1);
        $this->settings = [
            'capture_enabled' => (bool) $settings->capture_enabled,
            'site_capture_enabled' => (bool) $settings->capture_enabled && (bool) $settings->site_capture_enabled,
            'postbacks_enabled' => (bool) $settings->postbacks_enabled,
            'geo_edition' => ($settings->geo_edition ?? GeoEdition::GeoLite2City)->value,
            // The block rule's general thresholds, an amount as its column keeps it ("2.00", "2.0").
            ...BlockRule::effective($settings->only(BlockRule::KEYS)),
        ];
    }

    private function editedSource(): ?TrafficSource
    {
        return is_numeric($this->edit) ? TrafficSource::query()->find((int) $this->edit) : null;
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $done): void
    {
        try {
            $action();
        } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast($done);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }
}
