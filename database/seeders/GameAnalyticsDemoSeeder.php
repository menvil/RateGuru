<?php

namespace Database\Seeders;

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\CreateContentSetAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\SaveContentSetAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Actions\Traffic\AllocateSpendDayAction;
use App\Actions\Traffic\MaterializeFixedSpendAction;
use App\Actions\Traffic\RevalueOpenVisitsAction;
use App\Actions\Traffic\WorkOutChancesAction;
use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Data\Games\ContentSetDraft;
use App\Data\Games\GameFlowDraft;
use App\Enums\GameAudience;
use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GamePostTarget;
use App\Enums\TrafficDevice;
use App\Enums\TrafficEvent;
use App\Enums\TrafficSpendMode;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficConversion;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Analytics\LandingRoute;
use App\Support\Games\Flow\GameFlowTemplate;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoPlaythrough;
use Database\Seeders\Support\DemoTrafficMix;
use Database\Seeders\Support\DemoVisitor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use SplPriorityQueue;

/**
 * Two months of the demo game's life, for Growth → Games → Analytics and
 * Traffic sources to show: “Trust Your Taste” published, then a second and a
 * third version — an email before the result and a way on to a post; the
 * pairs reversed and played straight away, a share and the feed after — and
 * the traffic shared between them, period after period. More paid sources
 * beside TrafficDemoSeeder's: the ad networks of the presets and two banners
 * and links. And every visit played through, at the moment it happened.
 *
 * The visits are played, not made up: each runs through the actions the game
 * page, the sign-up forms and the tracker's own runs call, with the clock set
 * back to it — the paid click, the page run and the version it was given,
 * the attempt and its picks, the steps after the game, the emails, shares and
 * replays, the ways on to a post or the feed, sign-ups by email, Google and
 * Facebook, members signing in, confirmations minutes or days later or never,
 * late sign-ups from clicks whose window had closed, the visits settling —
 * so attempts, events, accounts and visits agree as they would on the site.
 * Two zones are robots: plenty of email sign-ups, next to no confirmations.
 *
 * TrafficDemoSeeder's clicks are kept and played through like the rest: what
 * it had made up about how far each got — and the conversions and postbacks
 * it had written for that — gives way to what playing them did. At the end
 * the accounts the history signed up and never confirmed within the week
 * are removed, as the nightly cleanup does, the banners' spend is shared
 * out over their visits, and what each step is worth is worked out again.
 *
 * Needs the large demo dataset, the demo game and the traffic demo, in this
 * order; this one takes a few minutes on a local PostgreSQL:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan db:seed --class=DemoFillSeeder
 *     php artisan db:seed --class=GamesDemoSeeder
 *     php artisan db:seed --class=TrafficDemoSeeder
 *     php artisan db:seed --class=GameAnalyticsDemoSeeder
 *
 * Safe to run again: once the game has a second version or an attempt,
 * nothing is added. Each visit is kept or undone whole, but a run stopped
 * half way leaves half a history: start again from migrate:fresh. Local and
 * testing environments only.
 */
class GameAnalyticsDemoSeeder extends Seeder
{
    private const int DAYS = 60;

    private const int SEED = 23;

    /** The members of the site who play signed in; the ones after them may sign in on a browser they played on. */
    private const int PLAYERS = 100;

    private const int MEMBERS = 200;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::query()->where('email', 'admin@rateguru.test')->first();
        $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->first();

        if ($admin === null || $game === null || ! TrafficSource::query()->where('link_key', 'pop')->exists()) {
            $this->command?->warn('Run DemoFillSeeder, GamesDemoSeeder and TrafficDemoSeeder first; nothing was added.');

            return;
        }

        if (GameVersion::query()->where('game_id', $game->id)->count() > 1 || GameAttempt::query()->where('game_id', $game->id)->exists()) {
            $this->command?->info('The demo game already has its history; nothing was changed.');

            return;
        }

        $members = User::query()->where('email', 'like', 'fill%@demo.test')->orderBy('id')->limit(self::MEMBERS)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if (count($members) < self::MEMBERS) {
            $this->command?->warn('Run DemoFillSeeder first: the game\'s players are its members. Nothing was added.');

            return;
        }

        $this->limitInMemory();
        mt_srand(self::SEED);
        $until = CarbonImmutable::now('UTC')->startOfSecond();
        $began = microtime(true);

        try {
            $play = $this->history($admin, $game, $members, $until);
        } finally {
            DemoPlaythrough::clock(null);
        }

        $done = collect($play->counts)->map(fn (int $count, string $what): string => number_format($count).' '.$what)->implode(', ');
        $this->command?->info(sprintf('Two months of %s played in %d s: %s.', $game->title, (int) round(microtime(true) - $began), $done));
    }

    /**
     * The visits of the two months, each at its moment, and the versions
     * published and the traffic shared among them on their days — all in
     * the order they happened, so a visitor is given the version the shares
     * of that day gave.
     *
     * @param  list<int>  $members
     */
    private function history(User $admin, Game $game, array $members, CarbonImmutable $until): DemoPlaythrough
    {
        $days = $this->days();
        $start = $until->subDays($days)->setTime(9, 30);
        $first = GameVersion::query()->where('game_id', $game->id)->sole();
        $mix = new DemoTrafficMix($admin);

        DB::transaction(function () use ($game, $first, $start, $mix): void {
            $this->backdate($game, $first, $start);
            DemoPlaythrough::clock($start->subDay());
            $mix->addSources($start->subDay());
        });

        $play = new DemoPlaythrough($game, $until, Hash::make('password'), array_slice($members, self::PLAYERS));
        $queue = new SplPriorityQueue;
        $serial = 0;
        $push = function (CarbonImmutable $at, array $entry) use ($queue, &$serial): void {
            // Earliest first; among equals, in the order queued.
            $queue->insert([...$entry, 'at' => $at], [-$at->getTimestamp(), -++$serial]);
        };

        // The second version a third of the way in, the third at two thirds, the shares moved again in the last week.
        $push($start->addDays(intdiv($days, 3))->setTime(10, 0), ['kind' => 'publish', 'step' => 'email']);
        $push($start->addDays(intdiv($days * 2, 3))->setTime(10, 0), ['kind' => 'publish', 'step' => 'direct']);
        $push($until->subDays(max(1, intdiv($days, 8)))->setTime(11, 0), ['kind' => 'publish', 'step' => 'shift']);

        foreach ($this->recordedClicks($game) as [$at, $visitor, $arrival]) {
            $push($at, ['kind' => 'visit', 'visitor' => $visitor, 'arrival' => $arrival]);
        }

        for ($day = 0; $day < $days; $day++) {
            $date = $start->addDays($day)->startOfDay();
            $arrivals = (int) round((26 + 44 * $day / $days) * ($date->isWeekend() ? 1.15 : 1.0) * (mt_rand(85, 115) / 100));

            for ($n = 0; $n < $arrivals; $n++) {
                $at = $date->addSeconds(self::daytime());
                $paid = mt_rand(1, 100) <= 78;
                $arrival = $paid ? ['kind' => 'paid', ...$mix->paidClick($day * 1000 + $n)] : ['kind' => 'unpaid', ...$mix->unpaidArrival()];
                $visitor = new DemoVisitor(hash('sha256', "demo-analytics-browser-{$day}-{$n}"), $arrival['country'], $arrival['device'], mt_rand(35, 88) / 100, $paid && $arrival['bot']);

                if ($at->greaterThanOrEqualTo($start) && $at->lessThanOrEqualTo($until)) {
                    $push($at, ['kind' => 'visit', 'visitor' => $visitor, 'arrival' => $arrival]);
                }
            }

            // Members of the site, signed in, from the feed: a few of them often.
            for ($n = 0, $sessions = (int) round(2 + 5 * $day / $days); $n < $sessions; $n++) {
                $at = $date->addSeconds(self::daytime());

                if ($at->greaterThanOrEqualTo($start) && $at->lessThanOrEqualTo($until)) {
                    $push($at, ['kind' => 'member', 'member' => $members[DemoTrafficMix::skewed(self::PLAYERS)]]);
                }
            }
        }

        $versions = ['first' => $first];
        $players = [];
        $today = null;

        while (! $queue->isEmpty()) {
            $entry = $queue->extract();

            // A dot a day played.
            if ($today !== $entry['at']->toDateString()) {
                $today = $entry['at']->toDateString();
                $this->command?->getOutput()->write('.');
            }

            if ($entry['kind'] === 'publish') {
                $this->publish($admin, $game, $versions, $entry['step'], $entry['at']);

                continue;
            }

            // A transaction a visit, not one around the whole history: held open for thousands
            // of actions, it leaves PostgreSQL no room for their locks.
            if ($entry['kind'] === 'member') {
                $id = $entry['member'];
                $players[$id] ??= new DemoVisitor(hash('sha256', 'demo-member-browser-'.$id), $mix->country(60), $id % 2 === 0 ? TrafficDevice::Mobile : TrafficDevice::Desktop, mt_rand(50, 90) / 100, memberId: $id);
                DB::transaction(fn () => $play->visit($players[$id], ['kind' => 'member', 'channel' => 'internal', 'origin' => 'feed'], $entry['at']));

                continue;
            }

            $back = DB::transaction(fn (): ?array => $play->visit($entry['visitor'], $entry['arrival'], $entry['at']));

            if ($back !== null) {
                $push($back['at'], ['kind' => 'visit', 'visitor' => $entry['visitor'], 'arrival' => $back['arrival']]);
            }
        }

        $this->command?->newLine();
        DemoPlaythrough::clock($until);
        $this->purgeUnconfirmed($play);
        $this->shareOutFixedSpend($until);
        app(WorkOutChancesAction::class)->handle('demo', $until);
        app(RevalueOpenVisitsAction::class)->handle($until);

        return $play;
    }

    /**
     * The version published and the traffic given on its day, as the
     * administrator does it from Versions and Traffic.
     *
     * @param  array<string, GameVersion>  $versions
     */
    private function publish(User $admin, Game $game, array &$versions, string $step, CarbonImmutable $at): void
    {
        DemoPlaythrough::clock($at);
        $first = (int) $versions['first']->id;
        $traffic = app(ApplyGameTrafficAction::class);

        if ($step === 'email') {
            // The email asked before the result, sign-up after it, then on to the post they last picked.
            $flow = app(ChangeGameFlowAction::class)->create($admin, $game, 'Email, then a post', GameFlowTemplate::EmailBeforeResult);
            $draft = GameFlowDraft::of($flow->load('steps'));
            $steps = $draft->steps;
            $post = GameFlowDraft::newStep(GameFlowStepType::GoToPost, 'post', $draft->drafts());
            $post['settings'] = [...$post['settings'], 'post_target' => GamePostTarget::LastPick->value];
            $steps[] = $post;
            app(SaveGameFlowAction::class)->handle($admin, $flow, new GameFlowDraft($steps, GameFlowGoal::Email->value), (int) $flow->lock_version);
            $versions['email'] = app(PublishGameVersionAction::class)->handle($admin, $game->fresh() ?? $game, (int) $versions['first']->content_set_id, (int) $flow->id);
            $email = (int) $versions['email']->id;

            foreach (GameAudience::cases() as $audience) {
                $traffic->handle($admin, $game->fresh() ?? $game, $audience, [$first => 50, $email => 50]);
            }

            return;
        }

        $email = (int) $versions['email']->id;

        if ($step === 'direct') {
            // The same pairs the other way round, each with its sides swapped, played straight away.
            $set = app(CreateContentSetAction::class)->handle($admin, $game, 'Reversed pairs', (int) $versions['first']->content_set_id);
            $set = GameContentSet::query()->with(['rounds.options', 'profiles'])->findOrFail($set->id);
            $draft = ContentSetDraft::of($set);
            $rounds = array_map(fn (array $round, int $index): array => [
                ...$round,
                'internal_name' => 'Round '.($index + 1),
                'question' => 'Which one won more upvotes?',
                'options' => array_reverse($round['options']),
            ], array_reverse($draft->rounds), array_keys($draft->rounds));
            app(SaveContentSetAction::class)->handle($admin, $set, new ContentSetDraft($draft->scoring, $rounds, $draft->profiles), (int) $set->lock_version);
            $flow = app(ChangeGameFlowAction::class)->create($admin, $game, GameFlowTemplate::DirectPlay->label(), GameFlowTemplate::DirectPlay);
            $versions['direct'] = app(PublishGameVersionAction::class)->handle($admin, $game->fresh() ?? $game, (int) $set->id, (int) $flow->id);
            $direct = (int) $versions['direct']->id;
            $traffic->handle($admin, $game->fresh() ?? $game, GameAudience::Anonymous, [$first => 20, $email => 40, $direct => 40]);
            $traffic->handle($admin, $game->fresh() ?? $game, GameAudience::Authenticated, [$email => 50, $direct => 50]);

            return;
        }

        // A week ago: more of the new visitors to the version that plays straight away.
        $traffic->handle($admin, $game->fresh() ?? $game, GameAudience::Anonymous, [$first => 10, $email => 30, (int) $versions['direct']->id => 60]);
    }

    /**
     * The first version, published when the demo game was made, moved back
     * to the start of the two months with the traffic it was given, the
     * game, its set and its flow with it: nobody has played it yet.
     */
    private function backdate(Game $game, GameVersion $first, CarbonImmutable $start): void
    {
        $made = $start->subDays(2);

        $game->newQuery()->whereKey($game->id)->update(['first_published_at' => $start, 'status_changed_at' => $start, 'created_at' => $made, 'updated_at' => $start]);
        GameContentSet::query()->whereKey($first->content_set_id)->update(['created_at' => $made, 'updated_at' => $made]);
        GameFlow::query()->whereKey($first->flow_id)->update(['created_at' => $made, 'updated_at' => $made]);
        GameVersion::query()->whereKey($first->id)->update(['published_at' => $start, 'created_at' => $start, 'updated_at' => $start]);
        GameTrafficPeriod::query()->where('game_id', $game->id)->update(['started_at' => $start, 'created_at' => $start, 'updated_at' => $start]);
    }

    /**
     * TrafficDemoSeeder's visits of the game, back to what their click or
     * arrival recorded — no step taken, no version on the visit or its
     * landing, no worth, no conversions — to be played through at the
     * moment each came, each on its own row.
     *
     * @return list<array{0: CarbonImmutable, 1: DemoVisitor, 2: array<string, mixed>}>
     */
    private function recordedClicks(Game $game): array
    {
        $visits = TrafficVisit::query()->where('game_id', $game->id)->orderBy('arrived_at')->orderBy('id')->get();
        $untouched = ['late_signup_at' => null, 'signup_place' => null, 'signup_method' => null, 'signup_after_seconds' => null, 'account_after_seconds' => null];

        foreach (TrafficEvent::cases() as $event) {
            $untouched[$event->column()] = null;
        }

        TrafficConversion::query()->whereIn('visit_id', $visits->modelKeys())->delete();
        $clicks = [];

        foreach ($visits as $visit) {
            $landing = LandingRoute::parts((string) $visit->landing_key);
            $visit->forceFill([
                ...$untouched,
                // An unpaid visit is recorded when its page runs.
                'opened_at' => $visit->source_id === null ? $visit->arrived_at : null,
                'last_seen_at' => $visit->arrived_at,
                'version_id' => null,
                // A session that began on the game's page: its landing learns again the version the page shows, as opening it writes it.
                'landing_key' => $visit->entry_visit_id === $visit->id && $landing['route'] === 'games.play' && $landing['gameId'] === (int) $game->id
                    ? LandingRoute::key('games.play', (int) $game->id, null)
                    : $visit->landing_key,
                'known' => false,
                'known_reason' => null,
                'rounds_answered' => 0,
                'value_real' => '0',
                'value_expected' => '0',
            ])->save();
            $country = [(string) $visit->country, (string) $visit->region, (string) $visit->city, (string) $visit->country_group];
            $clicks[] = [
                CarbonImmutable::instance($visit->arrived_at),
                new DemoVisitor((string) $visit->visitor_hash, $country, $visit->device, mt_rand(35, 88) / 100),
                ['kind' => 'recorded', 'visit_id' => (int) $visit->id, 'paid' => $visit->source_id !== null, 'opens' => 72, 'channel' => $visit->channel->value, 'origin' => $visit->origin],
            ];
        }

        return $clicks;
    }

    /**
     * The accounts the history signed up that never confirmed their address
     * within the week, removed as the nightly cleanup removes them; no other
     * account, whatever it is called.
     */
    private function purgeUnconfirmed(DemoPlaythrough $play): void
    {
        $cutoff = UnconfirmedAccountsQuery::cutoff();
        $purge = app(PurgeUnconfirmedAccountAction::class);

        foreach (array_chunk($play->created, 500) as $created) {
            foreach (app(UnconfirmedAccountsQuery::class)->expired($cutoff)->whereIn('id', $created)->orderBy('id')->pluck('id') as $id) {
                $purge->handle((int) $id, $cutoff);
            }
        }
    }

    /** The banners' and links' fixed prices, day by day, shared out over the visits of each day as the spend run does. */
    private function shareOutFixedSpend(CarbonImmutable $until): void
    {
        foreach (TrafficSource::query()->where('spend_mode', TrafficSpendMode::Fixed)->orderBy('id')->get() as $source) {
            app(MaterializeFixedSpendAction::class)->handle($source);

            foreach (TrafficSpendDay::query()->where('source_id', $source->id)->whereNull('allocated_at')->distinct()->orderBy('network_day')->pluck('network_day') as $day) {
                app(AllocateSpendDayAction::class)->handle($source, (string) $day, $until);
            }
        }
    }

    /**
     * The rate limiters on an in-memory store for the run: every visit
     * starts with them empty, and nothing of the history is left in the
     * application's cache.
     */
    private function limitInMemory(): void
    {
        config(['cache.limiter' => 'array']);
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
    }

    /** How many days back the history goes. */
    protected function days(): int
    {
        return self::DAYS;
    }

    /** A moment of the day people play: mostly afternoons and evenings, UTC. */
    private static function daytime(): int
    {
        $hour = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 18, 19, 19, 20, 20, 21, 21, 22, 23][mt_rand(0, 20)];

        return $hour * 3600 + mt_rand(0, 3599);
    }
}
