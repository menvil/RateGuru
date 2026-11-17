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
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Geo\Countries;
use App\Support\Traffic\Presets\SourceDefaults;
use App\Support\Traffic\Reports\SiteZoneList;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
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
    use WithFileUploads;

    public const array TABS = ['report' => 'Report', 'campaigns' => 'Campaigns', 'value' => 'Value', 'spend' => 'Spend', 'sources' => 'Sources', 'logs' => 'Logs'];

    public const array PERIODS = ['yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'];

    protected string $view = 'filament.pages.traffic-sources';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::GROWTH;

    protected static ?string $navigationLabel = 'Traffic sources';

    protected static ?string $title = 'Traffic sources';

    protected static ?string $slug = 'traffic-sources';

    protected static ?int $navigationSort = 2;

    #[Url(except: 'report')]
    public mixed $tab = 'report';

    #[Url(except: '30d')]
    public mixed $period = '30d';

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

    /** The report's view: a tree — by source, device, country, game or version — or the flat list of sites and zones. */
    #[Url(as: 'view', except: 'sources')]
    public mixed $reportView = 'sources';

    /** What Sign-ups and confirmation splits its sign-ups by: one of TrafficSourcesView::SIGNUP_DIMENSIONS. */
    #[Url(as: 'signups', except: 'source')]
    public mixed $signupsBy = 'source';

    /** @var list<string> the tree's rows opened, by their path; the ad networks to begin with */
    public array $open = ['type=network'];

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

    public ?TemporaryUploadedFile $spendFile = null;

    /** @var array{account_price: string, post_bonus: string, returned_bonus: string, login_price: string} */
    public array $prices = ['account_price' => '', 'post_bonus' => '', 'returned_bonus' => '', 'login_price' => ''];

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
        $this->normalize();
        // Campaigns' link starts on the first network, or the first banner, and the first game.
        $this->linkSource = (string) (TrafficSource::query()->where('type', TrafficSourceType::Network)->orderBy('name')->value('id')
            ?? TrafficSource::query()->where('type', TrafficSourceType::Placement)->orderBy('name')->value('id') ?? '');
        $this->linkGame = (string) (Game::query()->orderBy('title')->value('id') ?? '');
        $this->drawTargets();
        $firstPaid = TrafficSource::query()->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->orderBy('name')->value('id');
        $this->spendSource = is_numeric($this->spendSource) ? (string) (int) $this->spendSource : (string) ($firstPaid ?? '');
        $this->spendOpen = $firstPaid === null ? [] : [(int) $firstPaid];
        $this->drawPrices();
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
        if (in_array($property, ['tab', 'period', 'source', 'type', 'campaignId', 'game', 'device', 'log', 'logFilter', 'reportView', 'listBy', 'q', 'minClicks', 'segment'], true)) {
            $this->pageNumber = 1;
        }

        if (in_array($property, ['period', 'source', 'type', 'campaignId', 'game', 'device', 'reportView', 'listBy'], true)) {
            $this->selected = [];
        }

        if ($property === 'source') {
            $this->campaignId = '';
        }

        $this->normalize();
    }

    public function showTab(mixed $tab): void
    {
        $this->tab = $tab;
        $this->pageNumber = 1;
        $this->normalize();
    }

    /** Opens Report on one source: a banner's figures from its row in Campaigns. */
    public function reportFor(mixed $sourceId): void
    {
        $this->source = is_numeric($sourceId) ? (string) (int) $sourceId : '';
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
        // Looking up one site or zone: the row asked for shows, however few clicks it has.
        if ($this->q !== '') {
            $this->minClicks = 0;
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
        if (! is_string($key) || preg_match('/^\d+:(site|zone):.{1,120}$/', $key) !== 1) {
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
                app(BlockTrafficRefsAction::class)->block($this->admin(), $source, $level, $refs, null, $viaApi);
            } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
                $this->toast($exception->getMessage(), 'error');

                return;
            }

            $viaApi ? $queued += count($refs) : $byHand += count($refs);
        }

        $this->selected = [];
        $this->toast(trim(($queued > 0 ? $queued.' queued for the network. ' : '').($byHand > 0 ? $byHand.' marked as blocked: add them to the network’s list — Copy IDs gives them to you.' : '')));
    }

    /** Unblocks the sites and zones ticked. */
    public function unblockSelected(): void
    {
        $count = 0;

        foreach ($this->selectedRefs() as ['source' => $source, 'level' => $level, 'refs' => $refs]) {
            foreach (TrafficBlock::query()->where('source_id', $source->id)->where('level', $level)->whereIn('ref', $refs)->get() as $block) {
                try {
                    app(BlockTrafficRefsAction::class)->unblock($this->admin(), $block);
                } catch (CannotManageTrafficException|RateLimitExceededException $exception) {
                    $this->toast($exception->getMessage(), 'error');

                    return;
                }

                $count++;
            }
        }

        $this->selected = [];
        $this->toast($count.' unblocked');
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
     * read once.
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

            if ($source !== null && $level !== null) {
                $out[] = ['source' => $source, 'level' => $level, 'refs' => array_values(array_unique($group['refs']))];
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

        $this->toast('Test postback: '.$attempt->outcome->value.($attempt->httpStatus !== null ? ' · HTTP '.$attempt->httpStatus : '').($attempt->latencyMs !== null ? ' · '.$attempt->latencyMs.' ms' : ''), $attempt->outcome->value === 'ok' ? 'success' : 'error');
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
            $this->attempt(fn () => app(ChangeTrafficCampaignStageAction::class)->handle($this->admin(), $campaign, $stage), 'Stage: '.ucfirst($stage->value));
            $this->openCampaign($campaign->id);
        }
    }

    public function archiveCampaign(bool $restore = false): void
    {
        $campaign = TrafficCampaign::query()->find((int) $this->camp);

        if ($campaign !== null) {
            $this->attempt(fn () => $restore
                ? app(ArchiveTrafficCampaignAction::class)->restore($this->admin(), $campaign)
                : app(ArchiveTrafficCampaignAction::class)->archive($this->admin(), $campaign), $restore ? 'Campaign restored' : 'Campaign archived');
            $this->openCampaign($campaign->id);
        }
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

    /** A file chosen on Spend is taken as soon as it has uploaded. */
    public function updatedSpendFile(): void
    {
        if ($this->spendFile !== null) {
            $this->importSpend();
        }
    }

    public function importSpend(): void
    {
        $source = is_numeric($this->spendSource) ? TrafficSource::query()->find((int) $this->spendSource) : null;

        if ($source === null || $this->spendFile === null) {
            $this->toast('Choose a source and a file first.', 'error');

            return;
        }

        try {
            $import = app(EnterSpendAction::class)->file($this->admin(), $source, $this->spendFile->getClientOriginalName(), (string) file_get_contents($this->spendFile->getRealPath()));
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        } finally {
            $this->spendFile = null;
        }

        $this->toast($import->fact_rows.' figures imported'.($import->skipped_rows > 0 ? ', '.$import->skipped_rows.' rows of other campaigns left out' : ''));
    }

    // Value ----------------------------------------------------------------

    public function savePrices(): void
    {
        try {
            app(SaveTrafficPricesAction::class)->prices($this->admin(), $this->prices, $this->pricesFrom);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->drawPrices();
        $this->toast('Prices saved. Visits from now on count by them; earlier ones keep theirs.');
    }

    public function saveGroups(): void
    {
        $groups = array_map(fn (array $group): array => [...$group, 'countries' => array_values(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), explode(',', (string) $group['countries']))))], $this->groups);

        try {
            app(SaveTrafficPricesAction::class)->groups($this->admin(), $groups, $this->pricesFrom);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->drawPrices();
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
        return app(TrafficSourcesView::class)->data($this, $this->filter());
    }

    public function filter(): TrafficReportFilter
    {
        $days = match ($this->period) {
            'yesterday' => 1,
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };
        $base = TrafficReportFilter::lastDays($days, now()->toImmutable());
        $filter = $this->period === 'yesterday'
            ? new TrafficReportFilter(now()->utc()->subDay()->toDateString(), now()->utc()->subDay()->toDateString())
            : $base;
        $where = array_filter([
            'source_id' => is_numeric($this->source) ? (int) $this->source : null,
            'channel' => in_array($this->type, ['network', 'placement'], true) ? $this->type : null,
            'campaign_id' => is_numeric($this->campaignId) ? (int) $this->campaignId : null,
            'game_id' => is_numeric($this->game) ? (int) $this->game : null,
            'device' => in_array($this->device, ['mobile', 'desktop'], true) ? $this->device : null,
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
        $this->period = is_string($this->period) && array_key_exists($this->period, self::PERIODS) ? $this->period : '30d';
        $this->source = is_numeric($this->source) ? (string) (int) $this->source : '';
        $this->type = in_array($this->type, ['network', 'placement', 'unpaid'], true) ? $this->type : '';
        $this->campaignId = is_numeric($this->campaignId) ? (string) (int) $this->campaignId : '';
        $this->game = is_numeric($this->game) ? (string) (int) $this->game : '';
        $this->device = in_array($this->device, ['mobile', 'desktop'], true) ? $this->device : '';
        $this->reportView = is_string($this->reportView) && array_key_exists($this->reportView, TrafficSourcesView::VIEWS) ? $this->reportView : 'sources';
        $this->signupsBy = is_string($this->signupsBy) && array_key_exists($this->signupsBy, TrafficSourcesView::SIGNUP_DIMENSIONS) ? $this->signupsBy : 'source';
        $this->listBy = $this->listBy === 'site' ? 'site' : 'zone';
        $this->stage = in_array($this->stage, ['test', 'optimise', 'scale', 'paused', 'new', 'archived'], true) ? $this->stage : 'all';
        $this->spendSource = is_numeric($this->spendSource) ? (string) (int) $this->spendSource : '';
        $this->spendOpen = array_values(array_map('intval', array_filter($this->spendOpen, 'is_numeric')));
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 80) : '';
        $this->minClicks = is_numeric($this->minClicks) ? max(0, min(100000, (int) $this->minClicks)) : 0;
        $this->segment = is_string($this->segment) && array_key_exists($this->segment, SiteZoneList::SEGMENTS) ? $this->segment : 'all';
        $this->sort = is_string($this->sort) && in_array($this->sort, SiteZoneList::SORTS, true) ? $this->sort : 'spend';
        $this->dir = $this->dir === 'asc' ? 'asc' : 'desc';
        $this->open = array_values(array_filter($this->open, 'is_string'));
        $this->more = array_values(array_filter($this->more, 'is_string'));
        $this->selected = array_slice(array_values(array_filter($this->selected, 'is_string')), 0, 2000);
        $this->log = is_string($this->log) && in_array($this->log, ['clicks', 'milestones', 'postbacks', 'blocks'], true) ? $this->log : 'postbacks';
        $this->logFilter = $this->logFilter === 'failed' ? 'failed' : 'all';
        $this->pageNumber = is_numeric($this->pageNumber) ? max(1, (int) $this->pageNumber) : 1;
        $this->edit = is_string($this->edit) || is_int($this->edit) ? (string) $this->edit : '';
        $this->camp = is_numeric($this->camp) ? (string) (int) $this->camp : '';
    }

    private function drawPrices(): void
    {
        $set = TrafficPriceSet::current();
        $this->pricesFrom = $set->id;
        // Prices read as money: two decimals, more only where a price has them.
        $money = fn (string $amount): string => number_format((float) $amount, max(2, strlen(rtrim((string) substr((string) strrchr($amount, '.'), 1), '0'))), '.', '');
        $this->prices = ['account_price' => $money((string) $set->account_price), 'post_bonus' => $money((string) $set->post_bonus), 'returned_bonus' => $money((string) $set->returned_bonus), 'login_price' => $money((string) $set->login_price)];
        $this->groups = array_map(fn (array $group): array => ['key' => $group['key'], 'name' => $group['name'], 'percent' => $group['percent'], 'countries' => implode(', ', $group['countries'])], $set->country_groups);
    }

    private function drawSettings(): void
    {
        $settings = TrafficSetting::current();
        $this->settingsLock = (int) ($settings->lock_version ?? 1);
        $this->settings = [
            'capture_enabled' => (bool) $settings->capture_enabled,
            'postbacks_enabled' => (bool) $settings->postbacks_enabled,
            'geo_edition' => ($settings->geo_edition ?? GeoEdition::GeoLite2City)->value,
            'block_min_spend' => (string) $settings->block_min_spend,
            'block_min_clicks' => (int) $settings->block_min_clicks,
            'block_roi_below' => (int) $settings->block_roi_below,
            'block_open_below' => (int) $settings->block_open_below,
            'block_bot_clicks' => (int) $settings->block_bot_clicks,
            'block_start_clicks' => (int) $settings->block_start_clicks,
            'block_start_percent' => (int) $settings->block_start_percent,
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
