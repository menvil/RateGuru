<?php

namespace App\Livewire\Admin;

use App\Actions\Analytics\DeleteTrafficSegmentAction;
use App\Actions\Analytics\SaveTrafficSegmentAction;
use App\Enums\SegmentOperator;
use App\Enums\SegmentParameter;
use App\Enums\TrafficDevice;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;
use App\Queries\Analytics\TrafficSegmentsQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\SegmentRules;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A paid source's segment in the drawer over Analytics → Campaigns, as the
 * reference draws it: its name and its conditions — each a parameter of the
 * click, "is any of" or "is not", and its values (a device picked, the rest
 * typed) — every one of which a click must meet; with, as the conditions are
 * typed, how many of the source's visitors and accounts of the page's
 * period and device they catch, before the segment existed too. Saved
 * through SaveTrafficSegmentAction, deleted through
 * DeleteTrafficSegmentAction, from the drawer's own footer; the page closes
 * the drawer and says so — a delete with Undo.
 *
 * The drawer keeps the version it was drawn from: a save or a delete after
 * somebody else's save is refused and says so, and Reload draws the segment
 * as it is now; one deleted meanwhile can't be saved at all. Every request
 * asks again that the viewer may manage traffic sources. The drawer reads
 * its own figures, so typing never reads the whole tab again.
 *
 * @phpstan-import-type SegmentCondition from SegmentRules
 */
final class TrafficSegmentEditor extends Component
{
    #[Locked]
    public int $sourceId = 0;

    /** The segment edited; 0 while a new one is made. */
    #[Locked]
    public int $segmentId = 0;

    /** The page's period and device, which the preview reads. */
    #[Locked]
    public string $period = AnalyticsPeriod::DEFAULT;

    #[Locked]
    public string $from = '';

    #[Locked]
    public string $to = '';

    #[Locked]
    public string $device = '';

    /** The segment's lock_version as the draft was drawn from it. */
    #[Locked]
    public int $lockVersion = 0;

    /** Whether somebody else saved the segment since the draft was drawn. */
    #[Locked]
    public bool $stale = false;

    /** Whether the segment was deleted meanwhile. */
    #[Locked]
    public bool $gone = false;

    public mixed $name = '';

    /** @var list<SegmentCondition> */
    public array $conditions = [];

    public function boot(): void
    {
        abort_unless(GrowthAnalyticsPage::canAccess(), 403);
    }

    public function mount(int $sourceId, int $segmentId = 0, string $period = AnalyticsPeriod::DEFAULT, string $from = '', string $to = '', string $device = ''): void
    {
        abort_unless(TrafficSource::query()->whereKey($sourceId)->exists(), 404);

        $this->sourceId = $sourceId;
        $this->period = $period;
        $this->from = $from;
        $this->to = $to;
        $this->device = $device;

        if ($segmentId === 0) {
            $this->conditions = [self::blank(SegmentParameter::Site)];

            return;
        }

        $segment = TrafficSegment::query()->where('source_id', $sourceId)->find($segmentId);

        abort_if($segment === null, 404);

        $this->segmentId = (int) $segment->id;
        $this->draw($segment);
    }

    /** A change typed clears what was said about that field when it was saved. */
    public function updated(string $property): void
    {
        $this->conditions = self::tidy($this->conditions);
        $this->resetErrorBag($property);
    }

    /** Add condition: a device's, mobile to begin with, while there is room for another. */
    public function addCondition(): void
    {
        if (count($this->conditions) < SegmentRules::MOST_CONDITIONS) {
            $this->conditions[] = self::blank(SegmentParameter::Device);
            $this->resetErrorBag();
        }
    }

    /** A condition's ×, while another is left. */
    public function removeCondition(mixed $index): void
    {
        if (is_int($index) && array_key_exists($index, $this->conditions) && count($this->conditions) > 1) {
            array_splice($this->conditions, $index, 1);
            $this->resetErrorBag();
        }
    }

    /** What a condition reads: picked anew, its values with it. */
    public function pickParameter(mixed $index, mixed $parameter): void
    {
        $parameter = is_string($parameter) ? SegmentParameter::tryFrom($parameter) : null;

        if (! is_int($index) || ! array_key_exists($index, $this->conditions) || $parameter === null || $this->conditions[$index]['parameter'] === $parameter->value) {
            return;
        }

        $this->conditions[$index] = [...self::blank($parameter), 'operator' => $this->conditions[$index]['operator']];
        $this->resetErrorBag("conditions.{$index}.values");
    }

    /** A device of a device's condition: picked, or no longer. */
    public function toggleDevice(mixed $index, mixed $device): void
    {
        $device = is_string($device) ? TrafficDevice::tryFrom($device) : null;

        if (! is_int($index) || ! array_key_exists($index, $this->conditions) || $device === null || $this->conditions[$index]['parameter'] !== SegmentParameter::Device->value) {
            return;
        }

        $picked = SegmentRules::values($this->conditions[$index]['values']);
        $picked = in_array($device->value, $picked, true) ? array_values(array_diff($picked, [$device->value])) : [...$picked, $device->value];
        // Kept in the order the devices are offered.
        $this->conditions[$index]['values'] = implode(', ', array_values(array_filter(array_map(fn (TrafficDevice $each): string => $each->value, TrafficDevice::cases()), fn (string $value): bool => in_array($value, $picked, true))));
        $this->resetErrorBag("conditions.{$index}.values");
    }

    public function save(): void
    {
        if ($this->gone || $this->stale) {
            return;
        }

        try {
            $segment = app(SaveTrafficSegmentAction::class)->handle(
                $this->admin(),
                $this->sourceId,
                $this->segmentId === 0 ? null : $this->segmentId,
                ['name' => $this->name, 'conditions' => $this->conditions],
                $this->segmentId === 0 ? null : $this->lockVersion,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageTrafficException $exception) {
            $this->refuse($exception);

            return;
        }

        $this->dispatch('traffic-segment-saved', message: 'Segment “'.$segment->name.'” saved');
    }

    /** Delete segment: only marked, so the page offers Undo. */
    public function delete(): void
    {
        $segment = $this->segmentId === 0 ? null : TrafficSegment::query()->where('source_id', $this->sourceId)->find($this->segmentId);

        if ($segment === null) {
            $this->gone = $this->segmentId !== 0;

            return;
        }

        try {
            app(DeleteTrafficSegmentAction::class)->handle($this->admin(), $segment, $this->lockVersion);
        } catch (CannotManageTrafficException $exception) {
            $this->refuse($exception);

            return;
        }

        $this->dispatch('traffic-segment-deleted', id: (int) $segment->id, name: $segment->name);
    }

    /** Draws the segment as it is now, the draft with it. */
    public function reload(): void
    {
        $segment = $this->segmentId === 0 ? null : TrafficSegment::query()->where('source_id', $this->sourceId)->find($this->segmentId);

        if ($segment === null) {
            $this->gone = $this->segmentId !== 0;

            return;
        }

        $this->draw($segment);
        $this->stale = false;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $source = TrafficSource::query()->find($this->sourceId);
        $name = $source instanceof TrafficSource ? $source->name : 'The source';
        $conditions = self::tidy($this->conditions);
        ['rules' => $rules, 'errors' => $errors] = app(TrafficSegmentsQuery::class)->rules($this->sourceId, $name, $conditions);
        $filled = array_map(fn (array $condition): bool => SegmentRules::values($condition['values']) !== [], $conditions);
        $complete = ! in_array(false, $filled, true);

        return view('livewire.admin.traffic-segment-editor', [
            'creating' => $this->segmentId === 0,
            'sourceName' => $name,
            'draft' => $conditions,
            'parameters' => SegmentParameter::cases(),
            'operators' => array_combine(array_map(fn (SegmentOperator $operator): string => $operator->value, SegmentOperator::cases()), array_map(fn (SegmentOperator $operator): string => $operator->label(), SegmentOperator::cases())),
            'devices' => TrafficDevice::cases(),
            // What is wrong with a condition is said once it holds a value, or once a save asked.
            'wrong' => array_map(fn (int $index): ?string => $filled[$index] ? ($errors[$index] ?? null) : null, array_keys($conditions)),
            'preview' => match (true) {
                ! $complete => 'Fill every condition to see how many visitors it matches.',
                $errors !== [] => 'Fix the conditions marked to see how many visitors it matches.',
                default => $this->preview($rules, $name),
            },
            'canSave' => ! $this->stale && ! $this->gone && trim(is_string($this->name) ? $this->name : '') !== '' && $complete && $errors === [],
            'most' => SegmentRules::MOST_CONDITIONS,
        ]);
    }

    /**
     * What the rules catch of the source's visitors in the page's period
     * and device.
     *
     * @param  list<array{parameter: SegmentParameter, operator: SegmentOperator, values: list<int|string>}>  $rules
     */
    private function preview(array $rules, string $sourceName): string
    {
        $period = GrowthAnalyticsPage::readPeriod($this->period, $this->from, $this->to);
        $device = TrafficDevice::tryFrom($this->device);
        $figures = app(TrafficSegmentsQuery::class)->preview($this->sourceId, new AnalyticsFilter($period, $device), $rules);

        return 'Matches '.number_format($figures->visitors).' '.str('visitor')->plural($figures->visitors)
            .' and '.number_format($figures->accounts).' '.str('registration')->plural($figures->accounts)
            .' of '.$sourceName.' in '.$period->range().($device === null ? '' : ' on '.strtolower($device->label()))
            .', including visits before the segment existed.';
    }

    /** Says why nothing was saved, and keeps the drawer from saving what can no longer be. */
    private function refuse(CannotManageTrafficException $exception): void
    {
        if ($exception->isStale()) {
            $this->stale = true;
        }

        if ($exception->isGone()) {
            $this->gone = true;
        }

        $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');
    }

    private function draw(TrafficSegment $segment): void
    {
        $rules = SegmentRules::stored($segment->rules);

        $this->name = $segment->name;
        $this->conditions = self::tidy(SegmentRules::draft($rules, app(TrafficSegmentsQuery::class)->campaignRefs(SegmentRules::campaignIds($rules))));
        $this->lockVersion = $segment->lock_version;
    }

    private function admin(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * A new condition of a parameter: "is any of", and a device's on mobile.
     *
     * @return SegmentCondition
     */
    private static function blank(SegmentParameter $parameter): array
    {
        return ['parameter' => $parameter->value, 'operator' => SegmentOperator::In->value, 'values' => $parameter === SegmentParameter::Device ? TrafficDevice::Mobile->value : ''];
    }

    /**
     * The draft as the drawer holds it, whatever the browser sent: at most
     * the conditions a segment holds, each a parameter, an operator and its
     * values as text; at least one.
     *
     * @param  array<int|string, mixed>  $conditions
     * @return list<SegmentCondition>
     */
    private static function tidy(array $conditions): array
    {
        $tidy = [];

        foreach (array_slice(array_values($conditions), 0, SegmentRules::MOST_CONDITIONS) as $condition) {
            $condition = is_array($condition) ? $condition : [];
            $parameter = is_string($condition['parameter'] ?? null) ? SegmentParameter::tryFrom($condition['parameter']) : null;
            $operator = is_string($condition['operator'] ?? null) ? SegmentOperator::tryFrom($condition['operator']) : null;
            $tidy[] = [
                'parameter' => ($parameter ?? SegmentParameter::Site)->value,
                'operator' => ($operator ?? SegmentOperator::In)->value,
                'values' => is_string($condition['values'] ?? null) ? mb_substr($condition['values'], 0, 4000) : '',
            ];
        }

        return $tidy === [] ? [self::blank(SegmentParameter::Site)] : $tidy;
    }
}
