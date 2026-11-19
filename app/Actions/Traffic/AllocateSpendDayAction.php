<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\WorksOutClickSpend;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Support\Traffic\Spend\SpendAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shares one day of a source's spend out over its visits (SpendAllocator):
 * the day is counted from one origin — a typed total, else a file, else the
 * network's API where the source takes its spend from it, else the fixed
 * price — and that origin's figures are shared out over the visits they
 * cover: every visit of the day, or for the API every visit whose spend
 * waits for it — an estimate, or a figure from an origin since removed —
 * leaving out the campaigns priced their own way, whose API figures then
 * count for nothing. A day left with no figures goes back to what each
 * click was taken to cost as it came in. A visit is written only when its
 * spend moved, in batches — one statement for each spend a batch takes —
 * and each batch, and the figures' marks at the end, only while the day's
 * figures are still the ones the plan was drawn from: figures replaced
 * meanwhile stop the run and leave the day due, for the next run to share
 * out afresh; an interrupted run is finished by the next. A fixed price
 * stays an estimate while its day is not over.
 */
final class AllocateSpendDayAction
{
    use WorksOutClickSpend;

    private const int BATCH = 500;

    private const array ORDER = [TrafficSpendOrigin::Manual, TrafficSpendOrigin::Csv, TrafficSpendOrigin::Api, TrafficSpendOrigin::Fixed];

    private const array ALLOCATED = TrafficSpendBasis::ALLOCATED;

    public function handle(TrafficSource $source, string $day, CarbonImmutable $now): void
    {
        $facts = TrafficSpendDay::query()->where('source_id', $source->id)->where('network_day', $day)->orderBy('id')->get();
        $drawnFrom = $facts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        // The network's own figures count only for a source that takes its spend from them.
        $origin = collect(self::ORDER)->first(fn (TrafficSpendOrigin $candidate): bool => $facts->contains('origin', $candidate)
            && ($candidate !== TrafficSpendOrigin::Api || $source->spend_mode === TrafficSpendMode::Api));
        $start = CarbonImmutable::parse($day, $source->reporting_timezone)->startOfDay();
        $ofDay = fn () => TrafficVisit::query()
            ->where('source_id', $source->id)
            ->where('arrived_at', '>=', $start->utc())
            ->where('arrived_at', '<', $start->addDay()->utc())
            ->where('network_day', $day);

        if ($origin === null) {
            if ($this->backToClicks($source, $day, $drawnFrom, $ofDay()->whereIn('spend_basis', self::ALLOCATED)->orderBy('id')->get(['id', 'campaign_id', 'cost', 'device', 'arrived_at', 'spend', 'spend_basis', 'spend_estimated']))) {
                $this->markFacts($source, $day, $drawnFrom, $facts, collect(), ['facts' => []], $now);
            }

            return;
        }

        // Within an API-priced source, a campaign priced its own way keeps its own spend: the API's figures for it count for nothing.
        $ownPriced = $origin === TrafficSpendOrigin::Api
            ? TrafficCampaign::query()->where('source_id', $source->id)->whereNotNull('spend_mode')->whereNotIn('spend_mode', [TrafficSpendMode::Api->value, TrafficSpendMode::Manual->value])->pluck('id')->all()
            : [];
        $counted = $facts->where('origin', $origin)->reject(fn (TrafficSpendDay $fact): bool => in_array($fact->campaign_id, $ownPriced, true))->values();

        // Their clicks priced by a figure since removed go back to their own prices.
        if ($ownPriced !== [] && ! $this->backToClicks($source, $day, $drawnFrom, $ofDay()->whereIn('campaign_id', $ownPriced)->whereIn('spend_basis', self::ALLOCATED)->orderBy('id')->get(['id', 'campaign_id', 'cost', 'device', 'arrived_at', 'spend', 'spend_basis', 'spend_estimated']))) {
            return;
        }

        $visits = $ofDay()
            ->when($origin === TrafficSpendOrigin::Api, fn ($query) => $query
                ->where(fn ($covered) => $covered->where('spend_estimated', true)->orWhereIn('spend_basis', self::ALLOCATED))
                ->where(fn ($priced) => $priced->whereNull('campaign_id')->orWhereNotIn('campaign_id', $ownPriced === [] ? [0] : $ownPriced)))
            ->orderBy('id')
            ->get(['id', 'campaign_id', 'site_ref', 'zone_ref', 'cost', 'spend', 'spend_basis', 'spend_estimated']);

        $plan = SpendAllocator::plan(
            $counted->map(fn (TrafficSpendDay $fact): array => [
                'id' => (int) $fact->id, 'campaign_id' => $fact->campaign_id, 'site_ref' => $fact->site_ref, 'zone_ref' => $fact->zone_ref, 'amount' => (string) $fact->amount,
            ])->all(),
            $visits->map(fn (TrafficVisit $visit): array => [
                'id' => (int) $visit->id, 'campaign_id' => $visit->campaign_id, 'site_ref' => $visit->site_ref, 'zone_ref' => $visit->zone_ref, 'cost' => $visit->cost === null ? null : (string) $visit->cost,
            ])->all(),
        );
        $basis = TrafficSpendBasis::from($origin->value);
        $estimated = $origin === TrafficSpendOrigin::Fixed && $start->addDay()->greaterThan($now);

        foreach ($visits->chunk(self::BATCH) as $batch) {
            $written = DB::transaction(function () use ($source, $day, $drawnFrom, $batch, $plan, $basis, $estimated): bool {
                if (! $this->stillDrawnFrom($source, $day, $drawnFrom)) {
                    return false;
                }

                $moved = $batch->filter(fn (TrafficVisit $visit): bool => (string) $visit->spend !== $plan['visits'][(int) $visit->id] || $visit->spend_basis !== $basis || (bool) $visit->spend_estimated !== $estimated);

                foreach ($moved->groupBy(fn (TrafficVisit $visit): string => $plan['visits'][(int) $visit->id]) as $spend => $same) {
                    TrafficVisit::query()->whereKey($same->pluck('id')->all())->update(['spend' => (string) $spend, 'spend_basis' => $basis, 'spend_estimated' => $estimated]);
                }

                return true;
            });

            if (! $written) {
                return;
            }
        }

        $this->markFacts($source, $day, $drawnFrom, $facts, $counted, $plan, $now);
    }

    /**
     * Whether the day's figures are still the ones the plan was drawn from —
     * replaced figures have new IDs — asked with the source locked against a
     * typed total or a file, which lock it for update to replace figures, so
     * they stay so until the caller's transaction ends. Shared, not for
     * update: a click or a conversion of the source checks its key against
     * that row, and would otherwise wait on the batch, or deadlock with it
     * over a visit it holds.
     *
     * @param  list<int>  $drawnFrom
     */
    private function stillDrawnFrom(TrafficSource $source, string $day, array $drawnFrom): bool
    {
        TrafficSource::query()->whereKey($source->id)->sharedLock()->first();

        return TrafficSpendDay::query()->where('source_id', $source->id)->where('network_day', $day)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all() === $drawnFrom;
    }

    /**
     * @param  list<int>  $drawnFrom
     * @param  Collection<int, TrafficSpendDay>  $facts
     * @param  Collection<int, TrafficSpendDay>  $counted
     * @param  array{facts: array<int, array{allocated: string, matched: int}>}  $plan
     */
    private function markFacts(TrafficSource $source, string $day, array $drawnFrom, Collection $facts, Collection $counted, array $plan, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($source, $day, $drawnFrom, $facts, $counted, $plan, $now): void {
            if (! $this->stillDrawnFrom($source, $day, $drawnFrom)) {
                return;
            }

            foreach ($facts as $fact) {
                $isCounted = $counted->contains('id', $fact->id);
                TrafficSpendDay::query()->whereKey($fact->id)->update([
                    'counted' => $isCounted,
                    'allocated_amount' => $isCounted ? $plan['facts'][(int) $fact->id]['allocated'] : 0,
                    'matched_visits' => $isCounted ? $plan['facts'][(int) $fact->id]['matched'] : 0,
                    'allocated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Puts each visit back to what its click was taken to cost, once no
     * figure for its day is left to share out.
     *
     * @param  list<int>  $drawnFrom
     * @param  Collection<int, TrafficVisit>  $visits
     * @return bool whether every batch was written: false once the day's figures changed
     */
    private function backToClicks(TrafficSource $source, string $day, array $drawnFrom, Collection $visits): bool
    {
        $campaigns = TrafficCampaign::query()->whereKey($visits->pluck('campaign_id')->filter()->unique()->all())->get()->keyBy('id');

        foreach ($visits->chunk(self::BATCH) as $batch) {
            $written = DB::transaction(function () use ($batch, $source, $day, $drawnFrom, $campaigns): bool {
                if (! $this->stillDrawnFrom($source, $day, $drawnFrom)) {
                    return false;
                }

                $bySpend = [];

                foreach ($batch as $visit) {
                    $spend = $this->clickSpend($source, $visit->campaign_id === null ? null : $campaigns->get($visit->campaign_id), $visit->cost === null ? null : (string) $visit->cost, $visit->device, $visit->arrived_at);
                    $bySpend[(string) json_encode($spend)] ??= ['spend' => $spend, 'ids' => []];
                    $bySpend[(string) json_encode($spend)]['ids'][] = (int) $visit->id;
                }

                foreach ($bySpend as $same) {
                    TrafficVisit::query()->whereKey($same['ids'])->update($same['spend']);
                }

                return true;
            });

            if (! $written) {
                return false;
            }
        }

        return true;
    }
}
