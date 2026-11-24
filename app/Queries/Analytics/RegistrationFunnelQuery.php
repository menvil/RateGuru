<?php

namespace App\Queries\Analytics;

use App\Enums\SignupRefusal;
use App\Models\AnalyticsVisit;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\FunnelLanding;
use App\Support\Analytics\RegistrationFunnel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The registration funnel of a period's new visitors (Analytics → Site
 * engagement): the people — browsers, by their number — with a visit of the
 * period the filter and the landing admit, whose page ran, no member's and
 * no crawler's; and what each did over all their visits of the period, so a
 * sign-up a day after the landing counts. A step counts the people of the
 * steps before it, but for Google or Facebook, which can be started from the
 * sign-in form as well: started signing up is the registration form's email
 * sent, or a provider gone on to. With them, the people of each main step
 * who viewed a post, everyone who signed up by any way, what opened the
 * dialog and why sign-ups were refused. Read from Analytics' own rows only
 * (analytics_visits): null until they are ready. One pass a figure, each
 * person summed up first; every value bound.
 */
final class RegistrationFunnelQuery
{
    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    public function read(AnalyticsFilter $filter, FunnelLanding $landing): ?RegistrationFunnel
    {
        if (! $this->gate->reads()) {
            return null;
        }

        $people = $this->landed($filter, $landing);

        $persons = $this->period($filter)
            ->whereIn('visitor_key', $people)
            ->groupBy('visitor_key')
            ->select('visitor_key')
            ->selectRaw(implode(', ', array_map(
                fn (string $flag): string => "MAX(CASE WHEN {$flag} THEN 1 ELSE 0 END) AS {$flag}",
                ['auth_opened', 'register_form', 'signup_submitted', 'social_started', 'signed_up', 'account', 'activated', 'post_viewed'],
            )), []);

        $steps = [
            'opened' => 'auth_opened = 1',
            'register_form' => 'auth_opened = 1 AND register_form = 1',
            'started' => 'auth_opened = 1 AND ((register_form = 1 AND signup_submitted = 1) OR social_started = 1)',
            'sent_email' => 'auth_opened = 1 AND register_form = 1 AND signup_submitted = 1',
            'started_social' => 'auth_opened = 1 AND social_started = 1',
        ];
        $steps['signed_up'] = '('.$steps['started'].') AND signed_up = 1';
        $steps['confirmed'] = $steps['signed_up'].' AND account = 1';
        $steps['activated'] = $steps['confirmed'].' AND activated = 1';
        $main = ['landed' => '1 = 1', ...array_intersect_key($steps, array_flip(RegistrationFunnel::MAIN_STEPS))];

        $sums = ['COUNT(*) AS landed', 'SUM(CASE WHEN signed_up = 1 THEN 1 ELSE 0 END) AS signed_up_any'];

        foreach ($steps as $step => $condition) {
            $sums[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$step}";
        }

        foreach ($main as $step => $condition) {
            $sums[] = "SUM(CASE WHEN ({$condition}) AND post_viewed = 1 THEN 1 ELSE 0 END) AS viewed_{$step}";
        }

        $row = (array) AnalyticsVisit::query()->fromSub($persons, 'persons')->selectRaw(implode(', ', $sums), [])->toBase()->first();

        return new RegistrationFunnel(
            ['landed' => (int) ($row['landed'] ?? 0), ...array_map(fn (string $step): int => (int) ($row[$step] ?? 0), array_combine(array_keys($steps), array_keys($steps)))],
            array_map(fn (string $step): int => (int) ($row['viewed_'.$step] ?? 0), array_combine(array_keys($main), array_keys($main))),
            (int) ($row['signed_up_any'] ?? 0),
            $this->triggers($filter, $people),
            $this->refusals($filter, $people),
        );
    }

    /**
     * The people what opened the dialog brought, each under every opener of their visits.
     *
     * @param  Builder<AnalyticsVisit>  $people
     * @return array<string, int>
     */
    private function triggers(AnalyticsFilter $filter, Builder $people): array
    {
        return $this->period($filter)
            ->whereIn('visitor_key', $people)
            ->where('auth_opened', true)
            ->whereNotNull('auth_trigger')
            ->groupBy('auth_trigger')
            ->orderBy('auth_trigger')
            ->select('auth_trigger')
            ->selectRaw('COUNT(DISTINCT visitor_key) AS people', [])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->auth_trigger => (int) $row->people])
            ->all();
    }

    /**
     * The people refused for each reason, each under every reason they met; reasons nobody met left out.
     *
     * @param  Builder<AnalyticsVisit>  $people
     * @return array<string, int>
     */
    private function refusals(AnalyticsFilter $filter, Builder $people): array
    {
        $counts = [];
        $bindings = [];

        foreach (SignupRefusal::cases() as $refusal) {
            $counts[] = "COUNT(DISTINCT CASE WHEN (signup_refusals & ?) <> 0 THEN visitor_key END) AS {$refusal->value}";
            $bindings[] = $refusal->bit();
        }

        $row = (array) $this->period($filter)
            ->whereIn('visitor_key', $people)
            ->where('signup_refused', true)
            ->selectRaw(implode(', ', $counts), $bindings)
            ->toBase()
            ->first();

        return array_filter(
            array_combine(array_map(fn (SignupRefusal $refusal): string => $refusal->value, SignupRefusal::cases()), array_map(fn (SignupRefusal $refusal): int => (int) ($row[$refusal->value] ?? 0), SignupRefusal::cases())),
            fn (int $people): bool => $people > 0,
        );
    }

    /**
     * The people the funnel counts: a new visitor's visit of the period the filter and the landing admit, its page run.
     *
     * @return Builder<AnalyticsVisit>
     */
    private function landed(AnalyticsFilter $filter, FunnelLanding $landing): Builder
    {
        $rows = $this->period($filter)->where('opened', true)->where('known', false)->select('visitor_key');

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $rows->whereNull($column) : $rows->where($column, $value);
        }

        return $landing->narrow($rows);
    }

    /** @return Builder<AnalyticsVisit> the rows of the period's visits, a crawler's left out */
    private function period(AnalyticsFilter $filter): Builder
    {
        return AnalyticsVisit::query()
            ->whereBetween('network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore())
            ->where('crawler', false);
    }
}
