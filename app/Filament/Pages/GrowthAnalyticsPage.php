<?php

namespace App\Filament\Pages;

use App\Enums\TrafficDevice;
use App\Filament\Pages\Analytics\AnalyticsTab;
use App\Filament\Pages\Analytics\AnalyticsView;
use App\Filament\Pages\Analytics\ExperimentsTab;
use App\Filament\Pages\Analytics\OverviewTab;
use App\Filament\Support\AdminNavigationGroup;
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
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Growth → Analytics: where visitors come from and what they do on the whole
 * site, counted by RateGuru itself from the visits it records — people, not
 * visits. A period of whole days (its presets, or a range up to the longest
 * Analytics reads at a time), a device, a source and a campaign narrow every
 * figure of the tab open that reads visits; Experiments reads who each
 * traffic period assigned to which version instead, and none of them
 * applies to it. The strip lists only the tabs built so far.
 *
 * Everything on screen is in the address — the tab, the period, the device,
 * the source and campaign, a tab's own sorting, the comparison Experiments
 * shows — and anything it can't read is the default. Asking for the page
 * reads no visits: the open tab's figures are read once the page is in a
 * browser (wire:init), and only the open tab's. A source chosen whose
 * campaign isn't one of its own drops the campaign. The page, and every
 * request it answers, is an active administrator's who may manage traffic
 * sources.
 */
final class GrowthAnalyticsPage extends Page
{
    /** @var array<string, string> the tabs built so far, in the reference's order: the strip lists these and no other */
    public const array TABS = ['overview' => 'Overview', 'experiments' => 'Experiments'];

    /** @var array<string, class-string<AnalyticsTab>> what each tab of the strip reads */
    public const array TAB_DATA = ['overview' => OverviewTab::class, 'experiments' => ExperimentsTab::class];

    /**
     * @var list<string> the tabs that read no visits — Experiments reads who
     *                   was assigned to which version — so what the page says
     *                   about recorded visits, and the states it draws for
     *                   none, don't apply to them
     */
    public const array WITHOUT_VISITS = ['experiments'];

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

    /** One of AnalyticsPeriod::PRESETS; the default while a range is chosen. */
    #[Url(except: AnalyticsPeriod::DEFAULT)]
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

    /** Experiments: the comparison shown, a traffic period's id; the latest while none is named. */
    #[Url(except: '')]
    public mixed $exp = '';

    /** Whether the page is in a browser: only then does the open tab read its figures. */
    public bool $ready = false;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-traffic-sources');
    }

    /**
     * The period's days in the top bar (a panel render hook), read from the
     * page's own request; a period chosen later moves them by a browser
     * event the page sends.
     */
    public static function topbar(Request $request): string
    {
        if (! self::canAccess()) {
            return '';
        }

        $period = self::readPeriod($request->query('period'), $request->query('from'), $request->query('to'));

        return view('filament.pages.analytics.topbar', ['range' => $period->range()])->render();
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** The open tab's figures are read once the page is in a browser (wire:init), never by asking for it. */
    public function load(): void
    {
        $this->ready = true;
        $this->dropUnknownComparison();
    }

    public function updated(string $property): void
    {
        $this->normalize();

        if ($property === 'source') {
            $this->dropForeignCampaign();
        }

        if ($this->ready && in_array($property, ['tab', 'exp'], true)) {
            $this->dropUnknownComparison();
        }
    }

    public function choosePeriod(mixed $preset): void
    {
        $this->period = $preset;
        $this->from = '';
        $this->to = '';
        $this->periodChanged();
    }

    public function applyRange(mixed $from, mixed $to): void
    {
        $this->period = AnalyticsPeriod::DEFAULT;
        $this->from = $from;
        $this->to = $to;
        $this->periodChanged();
    }

    /** A row of Overview's sources table: filters the page by its source, or — chosen already — by every source again. */
    public function pickSource(mixed $key): void
    {
        $key = AnalyticsSource::read($key);

        if ($key === null) {
            return;
        }

        $this->source = $this->source === $key ? '' : $key;
        $this->dropForeignCampaign();
    }

    /** The chip's ×: every source again. */
    public function clearSource(): void
    {
        $this->source = '';
    }

    /** Every device, source and campaign again, the period kept. */
    public function clearFilters(): void
    {
        $this->device = '';
        $this->source = '';
        $this->campaign = '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $filter = $this->filter();
        $period = $filter->period;
        $view = app(AnalyticsView::class);
        $withoutVisits = in_array($this->tab, self::WITHOUT_VISITS, true);
        $recordedSince = $this->ready && ! $withoutVisits ? $view->recordedSince() : null;

        // Nothing to read yet when no visit was ever recorded, or none until after the period.
        $state = match (true) {
            ! $this->ready => 'loading',
            $withoutVisits => 'ready',
            $recordedSince === null => 'nothing',
            $period->toDay < $recordedSince->toDateString() => 'early',
            default => 'ready',
        };
        $shell = $view->shell($filter, $this->ready, $recordedSince);

        return [
            ...$shell,
            // Whether visits are recorded is nothing to a tab that reads none.
            'notices' => $withoutVisits ? [] : $shell['notices'],
            'ready' => $this->ready,
            'state' => $state,
            'since' => $recordedSince?->format('d M Y'),
            'range' => $period->range(),
            'tab' => (string) $this->tab,
            'tabs' => array_map(fn (string $id, string $label): array => ['id' => $id, 'label' => $label, 'attributes' => ['wire:click' => "\$set('tab', '{$id}')"]], array_keys(self::TABS), self::TABS),
            'deviceOptions' => [['value' => '', 'label' => 'All devices', 'trigger' => 'All'], ...array_map(fn (TrafficDevice $device): array => ['value' => $device->value, 'label' => $device->label()], TrafficDevice::cases())],
            'periodPicker' => $this->periodPicker($period),
            'data' => $state === 'ready' ? app()->make(self::TAB_DATA[(string) $this->tab], ['view' => $view])->data($this, $filter) : null,
        ];
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
    private static function readPeriod(mixed $preset, mixed $from, mixed $to): AnalyticsPeriod
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

    /** The period read back into the address, and its days into the top bar. */
    private function periodChanged(): void
    {
        $this->normalize();
        $this->dispatch('rg-admin-analytics-range', text: self::readPeriod($this->period, $this->from, $this->to)->range());
    }

    /** A period the address names that isn't a comparison Experiments lists — one version, hidden, of an archived game, gone — gives way to the latest. */
    private function dropUnknownComparison(): void
    {
        if ($this->exp !== '' && ! app(ComparisonPeriodsQuery::class)->isComparison((int) $this->exp)) {
            $this->exp = '';
        }
    }

    /** A campaign that isn't the chosen source's is dropped: both filters always hold together. */
    private function dropForeignCampaign(): void
    {
        if ($this->campaign !== '' && $this->source !== '' && ! app(AnalyticsView::class)->campaignBelongs($this->filter())) {
            $this->campaign = '';
        }
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
    }
}
