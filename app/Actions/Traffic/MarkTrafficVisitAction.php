<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficValueBasis;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Services\Traffic\TrafficVisitWorth;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Postbacks\MilestoneContext;
use App\Support\Traffic\Postbacks\PostbackPlanner;
use App\Support\Traffic\Postbacks\PostbackUrl;
use App\Support\Traffic\Worth\TrafficStep;
use App\Support\Traffic\Worth\VisitValue;
use Carbon\CarbonImmutable;

/**
 * The tracker's one writer of what a visit reached (docs/architecture/
 * traffic.md, "Conversions"). Under a lock on the visit, the first time
 * only: the event's mark and whatever the moment records beside it, a paid
 * visit still settling seen at that moment, its worth worked out again,
 * and — for a paid
 * click and an event a network can be told of — a conversion with what the
 * step was worth at that moment, and a postback waiting to be sent for each
 * rule on for it, unless PostbackPlanner says why not. A confirmed account
 * settles the visit then and there; a settled visit is worth the best step
 * it reached ("Settling"). It runs inside the transaction of whatever
 * reached the moment, so all of it is kept or undone with it. While
 * postbacks are switched off, conversions are kept and nothing is queued.
 * A visit the tracker doesn't count — an unpaid visit to the rest of the
 * site — keeps its marks and is worth nothing, whatever it reached.
 */
final class MarkTrafficVisitAction
{
    public function __construct(private readonly TrafficVisitWorth $worth) {}

    /**
     * Whether this call was the first to mark it.
     *
     * @param  array<string, mixed>  $also
     */
    public function handle(int $visitId, TrafficEvent $event, CarbonImmutable $at, array $also = [], ?MilestoneContext $context = null): bool
    {
        $visit = TrafficVisit::query()->whereKey($visitId)->lockForUpdate()->first();

        if ($visit === null || $visit->getAttribute($event->column()) !== null) {
            return false;
        }

        $visit->forceFill([$event->column() => $at, ...$also]);

        // A step keeps a paid visit from settling while it can still settle; settling itself
        // is no step, and a late mark — an account, a return, a first post — never brings an
        // old visit back into the windows that reuse a visit seen in the last half hour.
        if ($event !== TrafficEvent::Settled && $this->settling($visit, $at) && $at->greaterThan($visit->last_seen_at)) {
            $visit->forceFill(['last_seen_at' => $at]);
        }

        $values = $visit->isTracked() ? $this->worth->values($visit, $at) : VisitValue::nothing();
        $visit->forceFill(['value_real' => $values['real'], 'value_expected' => $values['expected']])->save();

        $this->convert($visit, $event, $at, $context ?? new MilestoneContext);

        if ($event === TrafficEvent::Account && $visit->settled_at === null) {
            $visit->forceFill(['settled_at' => $at])->save();
            $this->convert($visit, TrafficEvent::Settled, $at, new MilestoneContext);
        }

        return true;
    }

    /**
     * How deep into the game the visit has got: the rounds answered on it,
     * never fewer than before. Every round answered, a replay's too, counts
     * as a paid visit still settling seen now.
     */
    public function answered(int $visitId, int $rounds): void
    {
        $now = CarbonImmutable::now();

        TrafficVisit::query()->whereKey($visitId)->where('rounds_answered', '<', $rounds)->update(['rounds_answered' => $rounds]);
        TrafficVisit::query()->whereKey($visitId)
            ->whereNotNull('source_id')
            ->whereNull('settled_at')
            ->where('arrived_at', '>', $now->subHours((int) config('traffic.settle.after_hours', 24)))
            ->where('last_seen_at', '<', $now)
            ->update(['last_seen_at' => $now]);
    }

    /** Whether a step at this moment can still keep the visit from settling: a paid visit not settled, inside the settle hours. */
    private function settling(TrafficVisit $visit, CarbonImmutable $at): bool
    {
        return $visit->source_id !== null
            && $visit->settled_at === null
            && $at->lessThan($visit->arrived_at->addHours((int) config('traffic.settle.after_hours', 24)));
    }

    /**
     * Whether the visit got at least as far as a rule asks: the floor itself
     * or any step above it — an account is above every floor.
     */
    private static function reached(TrafficVisit $visit, TrafficEvent $floor): bool
    {
        $floors = TrafficEvent::settledFloors();

        foreach (array_slice($floors, (int) array_search($floor, $floors, true)) as $event) {
            if ($visit->getAttribute($event->column()) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The conversion of a paid click's event and its postbacks, for a visit
     * already locked and marked by its caller.
     */
    public function convert(TrafficVisit $visit, TrafficEvent $event, CarbonImmutable $at, MilestoneContext $context): void
    {
        if ($visit->source_id === null || ! $event->canPostBack() || ($event === TrafficEvent::Goal && $context->goal === null)) {
            return;
        }

        $rounds = $context->rounds > 0 ? $context->rounds : (int) ($visit->version_id === null ? 0 : GameVersion::query()->whereKey($visit->version_id)->value('round_count'));

        if ($event === TrafficEvent::Settled) {
            $worth = $this->worth->settled($visit);
            $step = $worth['step'];
        } else {
            $step = $event === TrafficEvent::Goal ? $context->goal->step() : TrafficStep::ofEvent($event, max(1, $rounds));
            $worth = $step === null ? ['value' => TrafficMoney::of(0), 'basis' => TrafficValueBasis::None] : $this->worth->step($visit, $step);
        }

        $source = TrafficSource::query()->find($visit->source_id);
        $details = TrafficVisitDetail::query()->find($visit->id);
        $on = TrafficPostbackRule::query()->where('source_id', $visit->source_id)->where('event', $event)->where('enabled', true)->orderBy('position')->get();
        // A rule may ask for a visit that got at least so far.
        $rules = $on->filter(fn (TrafficPostbackRule $rule): bool => $rule->needs_event === null || self::reached($visit, $rule->needs_event))->values();
        $plan = PostbackPlanner::plan(
            $event,
            (bool) $visit->known,
            $source?->status === TrafficSourceStatus::Active,
            ($details->click_id ?? '') !== '' && $visit->click_hash !== null,
            $visit->arrived_at,
            $visit->holds_until,
            $source?->click_id_ttl_hours,
            UnconfirmedAccountsQuery::GRACE_DAYS,
            $rules->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $at,
            CarbonImmutable::now(),
            accountStep: $step === 'account',
            tooShallow: $on->isNotEmpty() && $rules->isEmpty(),
        );

        $conversion = TrafficConversion::query()->create([
            'visit_id' => $visit->id,
            'event' => $event,
            'occurred_at' => $at,
            'source_id' => $visit->source_id,
            'game_id' => $context->gameId ?? $visit->game_id,
            'version_id' => $context->versionId ?? $visit->version_id,
            'attempt_id' => $context->attemptId,
            'step' => $step,
            'goal' => $context->goal?->value,
            'value' => TrafficMoney::stored($worth['value']),
            'value_basis' => $worth['basis'],
            'postback_skip' => $plan['skip'],
        ]);

        if ($plan['skip'] !== null || ! TrafficSetting::current()->postbacks_enabled) {
            return;
        }

        $values = $this->values($visit, $event, $conversion, $context, $source, $details, $worth['value']);

        foreach ($rules->whereIn('id', $plan['rules']) as $rule) {
            TrafficPostback::query()->create([
                'conversion_id' => $conversion->id,
                'rule_id' => $rule->id,
                'source_id' => $visit->source_id,
                'event' => $event,
                'url' => PostbackUrl::render($rule->url_template, [...$values, 'goal_code' => (string) $rule->goal_code]),
                'status' => TrafficPostbackStatus::Pending,
                'attempts' => 0,
                'run_after' => CarbonImmutable::now(),
                'expires_at' => $plan['expires_at'],
                'created_at' => CarbonImmutable::now(),
            ]);
        }
    }

    /** @return array<string, string> what each placeholder becomes, but the click ID and the goal code */
    private function values(TrafficVisit $visit, TrafficEvent $event, TrafficConversion $conversion, MilestoneContext $context, ?TrafficSource $source, ?TrafficVisitDetail $details, mixed $value): array
    {
        $gameId = $context->gameId ?? $visit->game_id;
        $versionId = $context->versionId ?? $visit->version_id;
        $values = [
            'event' => $event->value,
            'value' => TrafficMoney::forPostback($value),
            'value_total' => TrafficMoney::forPostback($this->worth->best($visit)),
            'currency' => 'USD',
            'conversion_id' => (string) $conversion->id,
            'device' => $visit->device->value,
            'game' => $gameId === null ? '' : (string) Game::query()->whereKey($gameId)->value('slug'),
            'version' => $versionId === null ? '' : 'V'.GameVersion::query()->whereKey($versionId)->value('number'),
        ];
        $refs = [
            'campaign' => $visit->campaign_id === null ? null : TrafficCampaign::query()->whereKey($visit->campaign_id)->value('external_id'),
            'site' => $visit->site_ref,
            'zone' => $visit->zone_ref,
            'creative' => $visit->creative_ref,
            'cost' => $visit->cost,
        ];

        foreach ($source->link_params ?? [] as $param) {
            if ($param['param'] !== '' && array_key_exists($param['kind'], $refs)) {
                $values['param:'.$param['param']] = (string) $refs[$param['kind']];
            }
        }

        foreach ($details->extra_params ?? [] as $name => $extra) {
            $values['param:'.$name] ??= (string) $extra;
        }

        return $values;
    }
}
