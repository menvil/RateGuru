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
use App\Enums\TrafficEvent;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\TrafficSources\TrafficSourcesView;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Traffic\Presets\SourceDefaults;
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

    /** What the report's tree is split by. */
    #[Url(except: 'source')]
    public mixed $by = 'source';

    /** The rows opened in the tree, as dimension:value pairs. */
    #[Url(except: '')]
    public mixed $path = '';

    /** The source editor: a preset to start from (`new:popads`, `new:placement`) or a source's id. */
    #[Url(except: '')]
    public mixed $edit = '';

    /** The campaign panel: a campaign's id. */
    #[Url(except: '')]
    public mixed $camp = '';

    #[Url(except: 'clicks')]
    public mixed $log = 'clicks';

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

    /** @var array{day: string, amount: string} */
    public array $spendEntry = ['day' => '', 'amount' => ''];

    public ?TemporaryUploadedFile $spendFile = null;

    /** @var array{account_price: string, post_bonus: string, returned_bonus: string} */
    public array $prices = ['account_price' => '', 'post_bonus' => '', 'returned_bonus' => ''];

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
        if (in_array($property, ['tab', 'period', 'source', 'by', 'log'], true)) {
            $this->pageNumber = 1;
            $this->path = $property === 'by' || $property === 'source' ? '' : $this->path;
        }

        $this->normalize();
    }

    public function showTab(mixed $tab): void
    {
        $this->tab = $tab;
        $this->pageNumber = 1;
        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /** Opens a row of the tree: the next level is split under it. */
    public function drill(mixed $dimension, mixed $value): void
    {
        if (! is_string($dimension) || ! is_scalar($value) && $value !== null) {
            return;
        }

        $this->path = trim($this->path.';'.$dimension.':'.($value ?? ''), ';');
        $this->by = TrafficSourcesView::nextDimension($dimension);
    }

    public function closePath(): void
    {
        $this->path = '';
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
        $this->draft['rules'][] = ['id' => null, 'event' => TrafficEvent::Goal->value, 'goal_code' => '', 'url_template' => '', 'enabled' => false];
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
        $source = is_numeric($this->source) ? TrafficSource::query()->find((int) $this->source) : null;

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

    public function importSpend(): void
    {
        $source = is_numeric($this->source) ? TrafficSource::query()->find((int) $this->source) : null;

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

    public function addGroup(): void
    {
        array_splice($this->groups, max(0, count($this->groups) - 1), 0, [['key' => 'group'.count($this->groups), 'name' => '', 'percent' => 100, 'countries' => '']]);
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
        $where = is_numeric($this->source) ? ['source_id' => (int) $this->source] : [];

        foreach (array_filter(explode(';', (string) $this->path)) as $step) {
            [$dimension, $value] = array_pad(explode(':', $step, 2), 2, '');
            $column = TrafficSourcesView::columnOf($dimension);

            if ($column !== null) {
                $where[$column] = $value === '' ? null : $value;
            }
        }

        return new TrafficReportFilter($filter->fromDay, $filter->toDay, $where);
    }

    private function normalize(): void
    {
        $this->tab = is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'report';
        $this->period = is_string($this->period) && array_key_exists($this->period, self::PERIODS) ? $this->period : '30d';
        $this->source = is_numeric($this->source) ? (string) (int) $this->source : '';
        $this->by = is_string($this->by) && TrafficSourcesView::columnOf($this->by) !== null ? $this->by : 'source';
        $this->path = is_string($this->path) ? mb_substr($this->path, 0, 400) : '';
        $this->log = is_string($this->log) && in_array($this->log, ['clicks', 'milestones', 'postbacks', 'blocks'], true) ? $this->log : 'clicks';
        $this->pageNumber = is_numeric($this->pageNumber) ? max(1, (int) $this->pageNumber) : 1;
        $this->edit = is_string($this->edit) || is_int($this->edit) ? (string) $this->edit : '';
        $this->camp = is_numeric($this->camp) ? (string) (int) $this->camp : '';
    }

    private function drawPrices(): void
    {
        $set = TrafficPriceSet::current();
        $this->pricesFrom = $set->id;
        $this->prices = ['account_price' => (string) $set->account_price, 'post_bonus' => (string) $set->post_bonus, 'returned_bonus' => (string) $set->returned_bonus];
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
