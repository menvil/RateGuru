<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Traffic\SetTrafficStepValueAction;
use App\Data\Games\GameFlowDraft;
use App\Enums\TrafficChannel;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficStepValueMode;
use App\Enums\TrafficValueBasis;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameFlow;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Queries\Traffic\GameWorthQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Worth\StepLabels;
use App\Support\Traffic\Worth\TrafficStep;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * A game's Value tab, as the reference draws it: what each step of a play is
 * worth — the chance that a new visitor who reached it becomes a confirmed
 * account, from the last working-out, times what an account brings — by
 * source, device and country group; the steps grouped as playing, after the
 * result, on the site and the account, each with how many reached it, its
 * chance, its worth and its tags — the Halfway round, the goal of a flow, the
 * postbacks that send it, a value set by hand — and set by hand in place.
 * Beside them: what a new visitor here is worth and an account brings, each
 * source's figures, the game's paid traffic over 30 days and the postbacks
 * that send these values. A value set here counts from the next postback,
 * with no new version.
 */
final class GameValue extends Page
{
    use InteractsWithGameEditor;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-value';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** “all”, a paid source as “s12”, or an unpaid channel as “c:search”. */
    #[Url(except: 'all')]
    public mixed $source = 'all';

    #[Url(except: 'all')]
    public mixed $device = 'all';

    #[Url(except: 'all')]
    public mixed $group = 'all';

    /** The step whose value is being set by hand, if any. */
    public ?string $editing = null;

    public string $editValue = '';

    public string $editMode = 'always';

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
        $this->normalizeFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'device', 'group'], true)) {
            $this->normalizeFilters();
            $this->editing = null;
        }
    }

    public function pickSource(mixed $source): void
    {
        $this->source = is_string($source) ? $source : 'all';
        $this->normalizeFilters();
    }

    /** Opens the step's value for setting by hand, or closes it when it is open. */
    public function editStep(mixed $step): void
    {
        $game = $this->game();

        if ($game === null || ! is_string($step) || in_array($step, ['account', 'login'], true)) {
            return;
        }

        if ($this->editing === $step) {
            $this->editing = null;

            return;
        }

        $data = $this->sliceData($game);
        $row = $data['steps'][$step] ?? null;

        if ($row === null) {
            return;
        }

        $this->editing = $step;
        $this->editValue = $row['manual'] !== null ? (string) (float) $row['manual']['value'] : number_format((float) (string) $row['worked'], 4, '.', '');
        $this->editMode = $row['manual']['mode']->value ?? TrafficStepValueMode::Always->value;
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function saveValue(): void
    {
        $game = $this->game();
        $step = $this->editing;

        if ($game === null || $step === null) {
            return;
        }

        $value = preg_replace('/[^0-9.]/', '', $this->editValue) ?? '';
        $mode = TrafficStepValueMode::tryFrom($this->editMode) ?? TrafficStepValueMode::Always;

        try {
            app(SetTrafficStepValueAction::class)->set($this->admin(), $game, $step, $value, $mode);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $rounds = app(GameWorthQuery::class)->rounds($game);
        $this->editing = null;
        $this->toast(StepLabels::name($step, $rounds).' set to '.TrafficMoney::fine($value).' by hand'.($mode === TrafficStepValueMode::Thin ? ' while a slice has fewer than 30 accounts' : '').'. Postbacks and fresh traffic use it from now on; finished periods keep their real outcomes.');
    }

    public function clearValue(): void
    {
        $game = $this->game();
        $step = $this->editing;

        if ($game === null || $step === null) {
            return;
        }

        try {
            app(SetTrafficStepValueAction::class)->clear($this->admin(), $game, $step);
        } catch (CannotManageTrafficException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->editing = null;
        $data = $this->sliceData($game);
        $this->toast(StepLabels::name($step, app(GameWorthQuery::class)->rounds($game)).' is worked out again: '.TrafficMoney::fine($data['steps'][$step]['value'] ?? '0').' here.');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $worth = app(GameWorthQuery::class);
        $rounds = $worth->rounds($game);
        $data = $this->sliceData($game);
        $set = TrafficPriceSet::current();
        $groups = collect($set->country_groups)->mapWithKeys(fn (array $group): array => [$group['key'] => $group['name']])->all();
        // The sources that sent the game visitors within the window its chances are worked out on.
        $breakdown = collect(app(TrafficReportQuery::class)->gameBreakdown((int) $game->id, CarbonImmutable::now()->subDays(120)));
        $sources = TrafficSource::query()->orderBy('name')->get(['id', 'name', 'type', 'status']);
        $visited = $this->visitedSources($breakdown, $sources);
        $flows = GameFlow::query()->where('game_id', $game->id)->whereNull('archived_at')->with('steps')->orderBy('position')->orderBy('id')->get();
        $goalSteps = $flows->groupBy(fn (GameFlow $flow): string => GameFlowDraft::of($flow)->goal()->step())->map(fn (Collection $group): string => $group->pluck('name')->implode(', '));
        $halfway = TrafficStep::halfway($rounds);
        $postbacks = $this->postbacks($game, $rounds, $goalSteps->keys()->all(), $halfway, $groups);
        $stepTags = [];

        foreach ($postbacks as $postback) {
            // A settled visit could tell any step: tagging them all would say nothing.
            if ($postback['event'] === TrafficEvent::Settled) {
                continue;
            }

            foreach ($postback['steps'] as $step) {
                $stepTags[$step][] = 'Postback · '.$postback['source'].($postback['code'] !== '' ? ' '.$postback['code'] : '').($postback['event'] === TrafficEvent::Goal ? ' (flow goal)' : '');
            }
        }

        $rows = [];

        foreach ($data['steps'] as $step => $row) {
            $rows[StepLabels::section($step)][] = [
                'step' => $step,
                'name' => StepLabels::name($step, $rounds),
                'note' => StepLabels::note($step, $rounds),
                'icon' => StepLabels::icon($step),
                'reached' => $row['reached'],
                'chance' => $step === 'login' ? null : $row['chance'],
                'worth' => (string) $row['value'],
                'worked' => $row['basis'] === TrafficValueBasis::Manual ? (string) $row['worked'] : null,
                'editable' => ! in_array($step, ['account', 'login'], true),
                'halfway' => $step === $halfway,
                'goal' => $goalSteps[$step] ?? null,
                'manual' => $row['manual'] === null ? null : ($row['manual']['mode'] === TrafficStepValueMode::Thin ? 'Set by hand while data is thin' : 'Set by hand'),
                'postbacks' => $stepTags[$step] ?? [],
            ];
        }

        $paid = collect(app(TrafficReportQuery::class)->gameBreakdown((int) $game->id, CarbonImmutable::now()->subDays(30)))
            ->filter(fn (array $row): bool => in_array($row['channel'], [TrafficChannel::Network->value, TrafficChannel::Placement->value], true))
            ->reduce(fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);

        return [
            'header' => $this->editorHeader($game, 'value'),
            'title' => $game->title,
            'sections' => [
                StepLabels::PLAYING => ['Playing', 'Every play goes through these. The further a visitor gets, the likelier an account.'],
                StepLabels::AFTER_THE_RESULT => ['After the result', 'What the visitor does on the result and on the steps after it.'],
                StepLabels::ON_THE_SITE => ['On the site', 'What they do on the post or in the feed that the flow sends them to.'],
                StepLabels::ACCOUNTS => ['Accounts', 'Signed up is worth the share that go on to confirm. Confirmed is what an account brings. Signing in to an existing account is worth nothing: we had them already.'],
            ],
            'rows' => $rows,
            'basis' => $this->basis($game, $data, $visited, $groups),
            'sourceOptions' => ['all' => 'All sources']
                + collect($visited)->mapWithKeys(fn (array $row): array => [$row['key'] => $row['name']])->all()
                + $sources->filter(fn (TrafficSource $source): bool => ! isset($visited['s'.$source->id]))->mapWithKeys(fn (TrafficSource $source): array => ['s'.$source->id => $source->name.' · no visits yet'])->all(),
            'deviceOptions' => ['all' => 'All devices', 'mobile' => 'Mobile', 'desktop' => 'Desktop'],
            'groupOptions' => ['all' => 'All countries'] + $groups,
            'visitor' => [
                'worth' => (string) $data['steps']['opened']['value'],
                'chance' => $data['steps']['opened']['chance'],
                'account' => (string) $data['account'],
                'formula' => TrafficMoney::brief($data['prices']->accountPrice)
                    .' + '.self::percent($data['postRate']).' post × '.TrafficMoney::brief($data['prices']->postBonus)
                    .' + '.self::percent($data['returnRate']).' back × '.TrafficMoney::brief($data['prices']->returnedBonus)
                    .($data['percent'] !== 100 ? ' · '.$data['percent'].'% for the group' : ''),
                'scope' => $this->scopeLabel($visited, $groups),
            ],
            'bySource' => $this->bySource($game, $rounds, $visited),
            'deviceLabel' => ['all' => 'Every device', 'mobile' => 'Mobile', 'desktop' => 'Desktop'][$this->device] ?? 'Every device',
            'paid' => $paid->visits > 0 ? $paid : null,
            'postbacks' => $postbacks,
            'pricesUrl' => TrafficSourcesPage::getUrl(['tab' => 'value']),
            'reportUrl' => TrafficSourcesPage::getUrl(['tab' => 'report', 'game' => $game->id]),
            'sourcesUrl' => TrafficSourcesPage::getUrl(['tab' => 'sources']),
            'worked' => $data['worked'],
        ];
    }

    /** @return array<string, mixed> the steps' worth in the slice the filters name */
    private function sliceData(Game $game): array
    {
        $worth = app(GameWorthQuery::class);
        [$sourceId, $channel] = $this->sourceFilter();

        return $worth->slice($game, $worth->rounds($game), $sourceId, $channel, $this->device === 'all' ? null : (string) $this->device, $this->group === 'all' ? null : (string) $this->group);
    }

    /** @return array{0: int|null, 1: string|null} */
    private function sourceFilter(): array
    {
        $source = (string) $this->source;

        return match (true) {
            str_starts_with($source, 's') && ctype_digit(substr($source, 1)) => [(int) substr($source, 1), null],
            str_starts_with($source, 'c:') => [null, substr($source, 2)],
            default => [null, null],
        };
    }

    private function normalizeFilters(): void
    {
        $this->source = is_string($this->source) && preg_match('/^(all|s\d+|c:[a-z]+)$/', $this->source) === 1 ? $this->source : 'all';
        $this->device = in_array($this->device, ['all', 'mobile', 'desktop'], true) ? $this->device : 'all';
        $groups = collect(TrafficPriceSet::current()->country_groups)->pluck('key')->all();
        $this->group = is_string($this->group) && ($this->group === 'all' || in_array($this->group, $groups, true)) ? $this->group : 'all';
    }

    /**
     * The sources and unpaid channels that sent visitors to the game, as the
     * filter and By source name them, by key (“s12”, “c:search”).
     *
     * @param  Collection<int, array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>  $breakdown
     * @param  Collection<int, TrafficSource>  $sources
     * @return array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>
     */
    private function visitedSources(Collection $breakdown, Collection $sources): array
    {
        $visited = [];

        foreach ($breakdown as $row) {
            if ($row['source_id'] !== null) {
                $visited['s'.$row['source_id']] = ['key' => 's'.$row['source_id'], 'name' => $sources->firstWhere('id', $row['source_id'])->name ?? 'Source #'.$row['source_id'], 'sourceId' => $row['source_id'], 'channel' => null];
            } else {
                $channel = TrafficChannel::tryFrom($row['channel']);
                $visited['c:'.$row['channel']] = ['key' => 'c:'.$row['channel'], 'name' => $channel?->label() ?? ucfirst($row['channel']), 'sourceId' => null, 'channel' => $row['channel']];
            }
        }

        return $visited;
    }

    /**
     * Each source's accounts in the game, what a player who finished the game
     * from it is worth, and what an account from it brings, on the device
     * chosen.
     *
     * @param  array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>  $visited
     * @return list<array{key: string, name: string, accounts: int, finished: string, account: string, current: bool}>
     */
    private function bySource(Game $game, int $rounds, array $visited): array
    {
        $worth = app(GameWorthQuery::class);
        $device = $this->device === 'all' ? null : (string) $this->device;
        $rows = [];

        foreach (['all' => ['key' => 'all', 'name' => 'All sources', 'sourceId' => null, 'channel' => null]] + $visited as $key => $source) {
            $data = $worth->slice($game, $rounds, $source['sourceId'], $source['channel'], $device);
            $rows[] = [
                'key' => $key,
                'name' => $source['name'],
                'accounts' => $data['accounts'],
                'finished' => (string) $data['steps']['completed']['value'],
                'account' => (string) $data['account'],
                'current' => $key === $this->source,
            ];
        }

        return $rows;
    }

    /**
     * Where the chances of the slice come from: its own confirmed accounts,
     * or, while it has fewer than 30, the slice above it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>  $visited
     * @param  array<string, string>  $groups
     * @return array{text: string, leaning: bool}
     */
    private function basis(Game $game, array $data, array $visited, array $groups): array
    {
        $want = $this->sliceName($game->title, $visited, $groups, $this->source, $this->device, $this->group);
        $stands = $data['stands'] === null ? 'the whole site' : $this->keyName($game->title, $data['stands'], $visited, $groups);

        return match (true) {
            ! $data['worked'] => ['text' => 'Not worked out yet: the chances need paid and unpaid visits two weeks old or more. Until then a step is worth what you set by hand, or $0, and an account what it brings.', 'leaning' => true],
            $data['visitors'] === 0 => ['text' => 'No visits to '.$game->title.' in this slice yet, so it is worked out from '.$stands.'. It moves to its own figures as accounts come in.', 'leaning' => true],
            $data['thin'] => ['text' => 'Only '.$data['accounts'].' confirmed '.($data['accounts'] === 1 ? 'account' : 'accounts').' in '.$want.' (fewer than 30), so it leans on '.$stands.' until more come in.', 'leaning' => true],
            default => ['text' => 'Worked out from '.$data['accounts'].' confirmed accounts in '.$want.', from visits whose 7-day window has closed. Updated every night.', 'leaning' => false],
        };
    }

    /**
     * @param  array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>  $visited
     * @param  array<string, string>  $groups
     */
    private function sliceName(string $title, array $visited, array $groups, mixed $source, mixed $device, mixed $group): string
    {
        return implode(' · ', array_filter([
            $title,
            $source !== 'all' ? ($visited[$source]['name'] ?? TrafficSource::query()->whereKey((int) substr((string) $source, 1))->value('name')) : null,
            $device !== 'all' ? (string) $device : null,
            $group !== 'all' ? ($groups[$group] ?? null) : null,
        ]));
    }

    /**
     * A slice key the chances lean on, named as the basis line reads it.
     *
     * @param  array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>  $visited
     * @param  array<string, string>  $groups
     */
    private function keyName(string $title, string $key, array $visited, array $groups): string
    {
        if ($key === '*') {
            return 'the whole site';
        }

        $source = 'all';
        $device = 'all';
        $group = 'all';
        $named = '';

        foreach (explode('|', $key) as $part) {
            match (true) {
                str_starts_with($part, 'g') => $named = $title,
                str_starts_with($part, 'd:') => $device = substr($part, 2),
                str_starts_with($part, 'k:') => $group = substr($part, 2),
                default => $source = $part,
            };
        }

        return $named === '' ? 'the whole site' : $this->sliceName($title, $visited, $groups, $source, $device, $group);
    }

    /**
     * @param  array<string, array{key: string, name: string, sourceId: int|null, channel: string|null}>  $visited
     * @param  array<string, string>  $groups
     */
    private function scopeLabel(array $visited, array $groups): string
    {
        return implode(', ', [
            $this->source === 'all' ? 'every source' : ($visited[$this->source]['name'] ?? 'this source'),
            ['all' => 'every device', 'mobile' => 'mobile', 'desktop' => 'desktop'][$this->device] ?? 'every device',
            $this->group === 'all' ? 'all countries' : ($groups[$this->group] ?? 'this group'),
        ]);
    }

    /**
     * The postback rules that send a step of this game: each with the steps
     * it sends, the range of values it sends across devices and country
     * groups, and whether its last postback failed.
     *
     * @param  list<string>  $goalSteps
     * @param  array<string, string>  $groups
     * @return list<array{id: int, sourceId: int, source: string, event: TrafficEvent, label: string, code: string, steps: list<string>, range: string, failing: bool, url: string}>
     */
    private function postbacks(Game $game, int $rounds, array $goalSteps, string $halfway, array $groups): array
    {
        $worth = app(GameWorthQuery::class);
        $rules = TrafficPostbackRule::query()
            ->where('enabled', true)
            ->whereHas('source', fn ($query) => $query->where('status', TrafficSourceStatus::Active->value)->where('type', TrafficSourceType::Network->value))
            ->with('source:id,name')
            ->orderBy('source_id')->orderBy('position')
            ->get();
        $values = [];
        $out = [];

        foreach ($rules as $rule) {
            $event = $rule->event;

            $steps = match ($event) {
                TrafficEvent::Goal => $goalSteps,
                TrafficEvent::Halfway => [$halfway],
                // A settled visit tells the best step it reached: any of them, from the rule's floor up.
                TrafficEvent::Settled => self::settledSteps($rounds, $rule->needs_event),
                default => TrafficStep::ofEvent($event, $rounds) === null ? [] : [TrafficStep::ofEvent($event, $rounds)],
            };

            if ($steps === []) {
                continue;
            }

            $sourceId = (int) $rule->source_id;

            if (! isset($values[$sourceId])) {
                $values[$sourceId] = [];

                foreach (['mobile', 'desktop'] as $device) {
                    foreach ([null, ...array_keys($groups)] as $group) {
                        $values[$sourceId][] = $worth->slice($game, $rounds, $sourceId, null, $device, $group)['steps'];
                    }
                }
            }

            $amounts = [];

            foreach ($values[$sourceId] as $slice) {
                foreach ($steps as $step) {
                    $amounts[] = (float) (string) ($slice[$step]['value'] ?? 0);
                }
            }

            $low = min($amounts);
            $high = max($amounts);
            $out[] = [
                'id' => (int) $rule->id,
                'sourceId' => $sourceId,
                'source' => (string) $rule->source?->name,
                'event' => $event,
                'label' => $event === TrafficEvent::Goal ? 'Flow goal reached' : $event->label(),
                'code' => trim((string) $rule->goal_code),
                'steps' => $steps,
                'range' => abs($high - $low) < 1e-9 ? TrafficMoney::fine($low) : TrafficMoney::fine($low).'–'.TrafficMoney::fine($high),
                'failing' => TrafficPostback::query()->where('rule_id', $rule->id)->orderByDesc('id')->value('status') === TrafficPostbackStatus::Failed->value,
                'url' => TrafficSourcesPage::getUrl(['tab' => 'sources', 'edit' => $sourceId]),
            ];
        }

        return $out;
    }

    /** A share as the Value tab reads it: whole from 10%, one decimal from 1%, two below. */
    public static function percent(float $share): string
    {
        $percent = $share * 100;

        return match (true) {
            $percent >= 10 => number_format($percent, 0).'%',
            $percent >= 1 => number_format($percent, 1).'%',
            default => number_format($percent, 2).'%',
        };
    }

    /**
     * The steps a Visit settled rule can tell: every step of the game from
     * the rule's floor up, the account always.
     *
     * @return list<string>
     */
    private static function settledSteps(int $rounds, ?TrafficEvent $floor): array
    {
        $steps = TrafficStep::all($rounds);
        $from = match ($floor) {
            null, TrafficEvent::Opened => 'opened',
            TrafficEvent::Halfway => TrafficStep::halfway($rounds),
            TrafficEvent::Goal, TrafficEvent::Account => 'account',
            default => TrafficStep::ofEvent($floor, $rounds) ?? 'opened',
        };
        $at = array_search($from, $steps, true);

        return array_values(array_unique([...($at === false ? [] : array_slice($steps, (int) $at)), 'account']));
    }
}
