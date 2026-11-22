<?php

namespace Database\Seeders;

use App\Actions\Analytics\SaveTrackingLinkAction;
use App\Actions\Analytics\SaveTrafficSegmentAction;
use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Traffic\SaveTrafficSettingsAction;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowGoal;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendBasis;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\Game;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameContentSet;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\Post;
use App\Models\TrafficCampaign;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\LandingRoute;
use App\Support\Analytics\UtmTags;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Traffic\TrafficScope;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoPlaythrough;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Load for Growth → Analytics, which reads straight from the visits: 4.5
 * million visits over the last 90 days and the 90 days before them — the
 * window a period is compared with — shaped as live capture records them,
 * so every tab can be timed on a local PostgreSQL against the limits the
 * page was built to (a read cache past 2 s a tab, totals kept aside past 5).
 *
 * Paid clicks of every source and its campaigns — sites, zones, creatives,
 * what each cost, crawlers and pages that never ran — and the site's own
 * arrivals: direct, from search engines, from Reddit, Facebook, Instagram,
 * TikTok and X, tagged and not, shared results, the newsletter, a long tail
 * of other sites and from inside the site. A session is its first visit and
 * a visit for each other game or for the rest of the site it went on to,
 * every one carrying where the session came from (its source, campaign,
 * creative, landing and tags) as capture copies them, and its first visit's
 * id. Browsers come back on later days; members of the site come signed
 * in, vote and comment. On the game: the version each browser was given by
 * the traffic period of the day — a second version published and the
 * traffic split between them, and split again for the first ad network —
 * the attempt, how far it went, the result seen and shared; then sign-ups,
 * confirmed or not, the new accounts and the days they came back, which
 * activates some. The posts read on the site's pages over the last 90 days
 * (their views are kept no longer), the tracking links the tagged arrivals
 * came by, and two segments of the first ad network.
 *
 * Rows are inserted in chunks under the 65 535 values a statement binds; a
 * session's later visits name its first by the id it is given, so ids are
 * taken from each table's own sequence one after another and checked after
 * every chunk. Needs the large demo dataset, the demo game unplayed and the
 * traffic demo, in this order:
 *
 *     php artisan db:seed --class=DemoFillSeeder
 *     php artisan db:seed --class=GamesDemoSeeder
 *     php artisan db:seed --class=TrafficDemoSeeder
 *     php artisan db:seed --class=AnalyticsVolumeSeeder
 *
 * At full size it takes some 1.6 KB of disk a visit — about 13 GB on
 * PostgreSQL — and the better part of an hour. On a disk short of room,
 * write less of the window before and the game's own rows for the last
 * days only, the visits of the window measured all there:
 *
 *     php artisan tinker --execute="(new Database\Seeders\AnalyticsVolumeSeeder(before: 0.2, gameDays: 30))->setContainer(app())->__invoke()"
 *
 * Never in DatabaseSeeder, never on a shared database; local and testing
 * environments only. Safe to run again: once its accounts are there,
 * nothing is added. A run cut short keeps what it wrote and can't be
 * resumed: start again from a fresh database.
 */
class AnalyticsVolumeSeeder extends Seeder
{
    private const int SEED = 53;

    /** The values one statement may bind at most, under the 65 535 PostgreSQL and MariaDB take and the 32 766 SQLite does. */
    private const array BOUND_VALUES = ['sqlite' => 30_000, 'default' => 60_000];

    /** The visits kept in memory before everything made with them is written. */
    private const int FLUSH_VISITS = 6000;

    /** The days post views are kept (traffic.retention_days.visit_details). */
    private const int POST_DAYS = 90;

    private const string EMAIL_DOMAIN = '@volume.example.test';

    /**
     * How the site's own visitors arrive: weight, channel, the referrer's
     * host (`tail` for one of many small sites), the tags of the link
     * followed, where they land — `feed`, `post`, the game's page (`play`)
     * or a shared result (`result`) — by weight, and the tags that vary from
     * link to link of the same campaign.
     *
     * @var array<string, array{0: int, 1: string, 2: string|null, 3: array<string, string>, 4: array<string, int>, 5: array<string, list<string>>}>
     */
    private const array ARRIVALS = [
        'direct' => [24, 'direct', null, [], ['feed' => 60, 'post' => 25, 'play' => 15], []],
        'google' => [13, 'search', 'www.google.com', [], ['feed' => 50, 'post' => 40, 'play' => 10], []],
        'bing' => [3, 'search', 'www.bing.com', [], ['feed' => 55, 'post' => 45], []],
        'duckduckgo' => [1, 'search', 'duckduckgo.com', [], ['feed' => 50, 'post' => 50], []],
        'reddit' => [6, 'referral', 'out.reddit.com', [], ['post' => 70, 'feed' => 20, 'play' => 10], []],
        'reddit-thread' => [6, 'referral', 'www.reddit.com', ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'taste-test-thread'], ['post' => 80, 'play' => 20], ['utm_content' => ['post_01', 'post_02']]],
        'google-ads' => [4, 'search', 'www.google.com', ['utm_source' => 'google', 'utm_medium' => 'paid_search', 'utm_campaign' => 'brand-search'], ['feed' => 100], ['utm_term' => ['rate food photos', 'photo rating site']]],
        'facebook' => [4, 'referral', 'l.facebook.com', [], ['post' => 80, 'feed' => 20], []],
        'facebook-promo' => [3, 'referral', 'l.facebook.com', ['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring-promo'], ['feed' => 50, 'play' => 50], ['utm_content' => ['carousel-a', 'carousel-b']]],
        'instagram-bio' => [2, 'referral', 'l.instagram.com', ['utm_source' => 'instagram', 'utm_medium' => 'organic_social', 'utm_campaign' => 'bio-link'], ['play' => 100], []],
        'newsletter' => [5, 'direct', null, ['utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'weekly-digest'], ['post' => 100], ['utm_content' => ['top-story', 'footer-link']]],
        'x' => [2, 'referral', 't.co', [], ['post' => 100], []],
        'shared' => [6, 'shared', null, [], ['result' => 100], []],
        'blogs' => [5, 'referral', 'tail', [], ['post' => 70, 'feed' => 30], []],
        'hacker-news' => [1, 'referral', 'news.ycombinator.com', [], ['feed' => 100], []],
        'food-blog' => [1, 'referral', 'foodblog.example', [], ['post' => 100], []],
        'tiktok' => [2, 'referral', 'www.tiktok.com', ['utm_source' => 'tiktok', 'utm_medium' => 'organic_social', 'utm_campaign' => 'creator-collab'], ['play' => 100], ['utm_content' => ['creator-a', 'creator-b', 'creator-c', 'creator-d', 'creator-e']]],
        'discord' => [1, 'direct', null, ['utm_source' => 'discord', 'utm_medium' => 'referral', 'utm_campaign' => 'server-invite'], ['feed' => 100], []],
        'inside' => [3, 'internal', 'own', [], ['feed' => 100], []],
    ];

    /** Where a member of the site comes in from: weight, by an arrival of ARRIVALS. */
    private const array MEMBER_ARRIVALS = ['direct' => 50, 'google' => 20, 'newsletter' => 20, 'reddit' => 10];

    /**
     * Where the visitors are: weight, then each city with its weight and
     * region; what is left of a hundred is a visitor whose city isn't known.
     * The empty code is a visitor whose country isn't known either.
     *
     * @var array<string, array{0: int, 1: list<array{0: string, 1: int, 2: string|null}>}>
     */
    private const array PLACES = [
        'US' => [22, [['New York', 16, 'NY'], ['Los Angeles', 12, 'CA'], ['Chicago', 8, 'IL'], ['Houston', 7, 'TX'], ['Miami', 6, 'FL'], ['Phoenix', 5, 'AZ'], ['Seattle', 5, 'WA'], ['Atlanta', 5, 'GA'], ['Denver', 4, 'CO'], ['Boston', 4, 'MA'], ['Columbus', 3, 'OH'], ['Portland', 3, 'OR']]],
        'GB' => [8, [['London', 38, 'ENG'], ['Manchester', 12, 'ENG'], ['Birmingham', 10, 'ENG'], ['Glasgow', 8, 'SCT'], ['Leeds', 6, 'ENG'], ['Bristol', 5, 'ENG'], ['Cardiff', 4, 'WLS']]],
        'DE' => [7, [['Berlin', 26, 'BE'], ['Hamburg', 14, 'HH'], ['Munich', 14, 'BY'], ['Cologne', 10, 'NW'], ['Frankfurt', 9, 'HE'], ['Stuttgart', 7, 'BW']]],
        'IN' => [8, [['Mumbai', 20, 'MH'], ['Delhi', 20, 'DL'], ['Bengaluru', 16, 'KA'], ['Hyderabad', 10, 'TG'], ['Chennai', 9, 'TN'], ['Kolkata', 8, 'WB']]],
        'BR' => [7, [['São Paulo', 30, 'SP'], ['Rio de Janeiro', 20, 'RJ'], ['Belo Horizonte', 9, 'MG'], ['Brasília', 8, 'DF'], ['Salvador', 7, 'BA']]],
        'FR' => [5, [['Paris', 40, 'IDF'], ['Lyon', 12, 'ARA'], ['Marseille', 11, 'PAC'], ['Toulouse', 8, 'OCC']]],
        'CA' => [4, [['Toronto', 34, 'ON'], ['Montreal', 22, 'QC'], ['Vancouver', 18, 'BC'], ['Calgary', 10, 'AB']]],
        'ES' => [4, [['Madrid', 34, 'MD'], ['Barcelona', 30, 'CT'], ['Valencia', 10, 'VC'], ['Seville', 8, 'AN']]],
        'MX' => [4, [['Mexico City', 44, 'CMX'], ['Guadalajara', 16, 'JAL'], ['Monterrey', 14, 'NLE']]],
        'AU' => [3, [['Sydney', 36, 'NSW'], ['Melbourne', 32, 'VIC'], ['Brisbane', 12, 'QLD'], ['Perth', 10, 'WA']]],
        'IT' => [3, [['Rome', 30, '62'], ['Milan', 30, '25'], ['Naples', 12, '72'], ['Turin', 10, '21']]],
        'PL' => [3, [['Warsaw', 36, '14'], ['Kraków', 18, '12'], ['Wrocław', 14, '02']]],
        'PH' => [3, [['Manila', 40, '00'], ['Quezon City', 22, '00'], ['Cebu City', 14, '07']]],
        'ID' => [3, [['Jakarta', 44, 'JK'], ['Surabaya', 16, 'JI'], ['Bandung', 14, 'JB']]],
        'TR' => [2, [['Istanbul', 54, '34'], ['Ankara', 18, '06'], ['Izmir', 12, '35']]],
        'NL' => [2, [['Amsterdam', 40, 'NH'], ['Rotterdam', 20, 'ZH'], ['Utrecht', 12, 'UT']]],
        '' => [2, []],
    ];

    /** @var array<string, int> what was made, for the run's summary */
    private array $counts = ['visits' => 0, 'post rows' => 0, 'assignments' => 0, 'attempts' => 0, 'events' => 0, 'accounts' => 0, 'activity days' => 0];

    private int $now;

    private int $postsFrom;

    /** The first moment the game's own rows are written for. */
    private int $gameFrom;

    private string $host;

    private string $password;

    private int $gameId;

    /** @var array<int, array{rounds: int, maxScore: int, accountGoal: bool, worse: bool}> the game's versions by id */
    private array $versions = [];

    /**
     * The traffic periods in force, each from its moment: everyone's for
     * each audience, and the splits of a source for its anonymous visitors.
     *
     * @var array{anonymous: list<array{0: int, 1: int, 2: array<int, int>}>, authenticated: list<array{0: int, 1: int, 2: array<int, int>}>, sources: array<int, list<array{0: int, 1: int, 2: array<int, int>}>>}
     */
    private array $periods = ['anonymous' => [], 'authenticated' => [], 'sources' => []];

    /** @var list<array{id: int, network: bool, creatives: bool, basis: string, rate: float, timezone: DateTimeZone|null, days: int, campaigns: list<array{0: int, 1: int}>}> */
    private array $sources = [];

    /** @var list<int> the paid sources, by their index in, as often as each is picked */
    private array $sourceSlots = [];

    /** @var list<int> published posts, the most voted on first */
    private array $posts = [];

    /** @var list<array{0: int, 1: string}> members of the site, by id, with the browser they come on */
    private array $members = [];

    /** @var array<int, array{0: int|null, 1: int}> a member's assignment of the game, by user id, and its version */
    private array $memberAssignments = [];

    /** @var array<string, list<string>> weighted slots, by what they pick */
    private array $slots = [];

    /** @var list<string> the countries, by index */
    private array $countryCodes = [];

    /** @var array<string, array{key: string, percent: int}> the worth group of each country, by code */
    private array $groups = [];

    /** @var array<string, array<string, mixed>> what a session's arrival writes on its visits, by arrival and host */
    private array $entryKeys = [];

    private int $priceSet;

    /** The moment the clock was held at when the run began, if it was: given back after each change made at its own moment. */
    private ?CarbonImmutable $heldClock = null;

    /** @var list<array{0: int, 1: \Closure(): void}> what the administrator changes on the game, each at its moment, the earliest first */
    private array $moments = [];

    private int $browsers = 0;

    /** Each browser's assignment of the game, four bytes a browser: the id less the first one's, plus one; nothing for none. */
    private string $assigned = '';

    /** Each browser's version index on its assignment, one byte a browser. */
    private string $assignedVersion = '';

    /** @var list<int> the game's version ids, by index */
    private array $versionIds = [];

    private int $nextVisit;

    private int $nextAssignment;

    private int $firstAssignment;

    private int $nextAttempt;

    private int $accountsMade = 0;

    private int $clicks = 0;

    /** @var array<string, list<array<string, mixed>>> rows waiting to be written, by table */
    private array $rows = ['visits' => [], 'posts' => [], 'assignments' => [], 'attempts' => [], 'events' => []];

    /** @var list<array{user: array<string, mixed>, attribution: array<string, mixed>, browser: array<string, mixed>|null, days: list<array<string, mixed>>, member: string|null}> accounts waiting to be written */
    private array $accounts = [];

    /** @var array<string, mixed> every column a visit is written with, empty */
    private array $blank = [];

    /**
     * @param  int  $visits  the visits of the last `$days` days
     * @param  int  $days  the length of the window measured, and of the window before it
     * @param  float  $before  how much of what the site's growth gave the window before is written: all of it by default, less on a disk short of room
     * @param  int|null  $gameDays  the last days the game's assignments, attempts and events are written for, every day while none is given: the visits carry their game, version and steps either way
     */
    public function __construct(
        private readonly int $visits = 4_500_000,
        private readonly int $days = 90,
        private readonly float $before = 1.0,
        private readonly ?int $gameDays = null,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::query()->where('email', 'admin@rateguru.test')->first();
        $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->first();
        $sources = TrafficSource::query()->orderBy('id')->get();

        if ($admin === null || $game === null || $sources->isEmpty()) {
            $this->command?->warn('Run DemoFillSeeder, GamesDemoSeeder and TrafficDemoSeeder first; nothing was added.');

            return;
        }

        if (User::query()->where('email', 'like', '%'.self::EMAIL_DOMAIN)->exists()) {
            $this->command?->info('The volume is already there, or a run cut short left part of it; nothing was added. To write it again, start from a fresh database: migrate:fresh and the seeders this one needs.');

            return;
        }

        if (GameVersion::query()->where('game_id', $game->id)->count() > 1 || GameAttempt::query()->where('game_id', $game->id)->exists()) {
            $this->command?->warn('The demo game already has a history of its own: run this on a database where it was never played. Nothing was added.');

            return;
        }

        $this->posts = Post::query()->published()->orderByDesc('upvotes_count')->orderBy('id')->limit(200)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if (count($this->posts) < 5) {
            $this->command?->warn('Run DemoFillSeeder first: the site needs published posts. Nothing was added.');

            return;
        }

        mt_srand(self::SEED);
        $began = microtime(true);
        $this->heldClock = CarbonImmutable::hasTestNow() ? CarbonImmutable::now() : null;
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $this->now = $now->getTimestamp();
        $today = $now->startOfDay();
        $start = $today->subDays(2 * $this->days - 1);
        $this->postsFrom = $today->subDays(self::POST_DAYS - 1)->getTimestamp();
        $this->gameFrom = $this->gameDays === null ? PHP_INT_MIN : $today->subDays(max(1, $this->gameDays) - 1)->getTimestamp();
        $this->host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $this->password = Hash::make('password');
        $this->gameId = (int) $game->id;

        try {
            $this->layOut($admin, $game, $sources->all(), $start);
            $this->prepare();
            $this->history($start->getTimestamp(), $today->getTimestamp());
            $this->flush();
        } finally {
            DemoPlaythrough::clock($this->heldClock);
        }

        $this->command?->newLine();

        $done = collect($this->counts)->map(fn (int $count, string $what): string => number_format($count).' '.$what)->implode(', ');
        $this->command?->info(sprintf('%d days of visits written in %d s: %s.', 2 * $this->days, (int) round(microtime(true) - $began), $done));
    }

    /**
     * What the visits are of, at the start: the game and its first version
     * moved back to it, recording switched on, more campaigns for the ad
     * networks, the tracking links and two segments of the first.
     *
     * @param  list<TrafficSource>  $sources
     */
    private function layOut(User $admin, Game $game, array $sources, CarbonImmutable $start): void
    {
        $first = GameVersion::query()->where('game_id', $game->id)->sole();
        $opened = $start->subHours(2);
        $made = $start->subDays(2);

        DemoPlaythrough::clock($opened);
        $game->newQuery()->whereKey($game->id)->update(['first_published_at' => $opened, 'status_changed_at' => $opened, 'created_at' => $made, 'updated_at' => $opened]);
        GameContentSet::query()->whereKey($first->content_set_id)->update(['created_at' => $made, 'updated_at' => $made]);
        GameFlow::query()->whereKey($first->flow_id)->update(['created_at' => $made, 'updated_at' => $made]);
        $first->newQuery()->whereKey($first->id)->update(['published_at' => $opened, 'created_at' => $opened, 'updated_at' => $opened]);
        GameTrafficPeriod::query()->where('game_id', $game->id)->update(['started_at' => $opened, 'created_at' => $opened, 'updated_at' => $opened]);

        $settings = TrafficSetting::current();
        app(SaveTrafficSettingsAction::class)->handle($admin, ['capture_enabled' => true, 'site_capture_enabled' => true], (int) $settings->lock_version);
        $this->campaigns($sources, $start);
        $this->links($admin, $game);
        $network = collect($sources)->first(fn (TrafficSource $source): bool => $source->type === TrafficSourceType::Network);

        if ($network instanceof TrafficSource) {
            $this->segments($admin, (int) $network->id);
        }

        $this->schedule($admin, $game, (int) $first->id, $network instanceof TrafficSource ? (int) $network->id : null, $start);
        $this->readGame();

        $this->sources = [];

        foreach ($sources as $index => $source) {
            $network = $source->type === TrafficSourceType::Network;
            $campaigns = TrafficCampaign::query()->where('source_id', $source->id)->orderBy('id')->get(['id', 'first_click_at']);
            $this->sources[] = [
                'id' => (int) $source->id,
                'network' => $network,
                'creatives' => $network && $source->preset_key !== 'popads',
                'basis' => match (true) {
                    ! $network => TrafficSpendBasis::Fixed->value,
                    $source->preset_key === 'popads' => TrafficSpendBasis::ClickCost->value,
                    default => TrafficSpendBasis::Api->value,
                },
                'rate' => $network ? ($source->preset_key === 'popads' ? 1.0 : 1.6) : 0.0,
                'timezone' => in_array((string) $source->reporting_timezone, ['', 'UTC'], true) ? null : new DateTimeZone((string) $source->reporting_timezone),
                'days' => max(1, (int) $source->attribution_days),
                'campaigns' => $campaigns->map(fn (TrafficCampaign $campaign): array => [(int) $campaign->id, $campaign->first_click_at->getTimestamp()])->all(),
            ];
            $weight = $network ? [55, 35][count(array_filter($this->sources, fn (array $each): bool => $each['network'])) - 1] ?? 20 : 10;
            array_push($this->sourceSlots, ...array_fill(0, $weight, $index));
        }
    }

    /**
     * What the administrator changes on the game while the visits come, at
     * its moment: a second version, played straight away, given half of
     * everyone a third of the way in; more of the new visitors to it a while
     * into the window measured; and the first ad network's visitors split
     * the other way three weeks before its end. Each is made once the
     * visits before it are written, so the period it ends had visitors and
     * a new one begins, as on the site.
     */
    private function schedule(User $admin, Game $game, int $first, ?int $network, CarbonImmutable $start): void
    {
        $traffic = app(ApplyGameTrafficAction::class);
        $end = $start->addDays(2 * $this->days - 1);
        $second = null;

        $this->moments = [
            [$start->addDays((int) round(0.6 * $this->days))->setTime(10, 0)->getTimestamp(), function () use ($admin, $game, $first, $traffic, &$second): void {
                $set = (int) GameVersion::query()->whereKey($first)->value('content_set_id');
                $flow = app(ChangeGameFlowAction::class)->create($admin, $game, GameFlowTemplate::DirectPlay->label(), GameFlowTemplate::DirectPlay);
                $second = (int) app(PublishGameVersionAction::class)->handle($admin, $game->fresh() ?? $game, $set, (int) $flow->id)->id;

                foreach (GameAudience::cases() as $audience) {
                    $traffic->handle($admin, $game->fresh() ?? $game, $audience, [$first => 50, $second => 50]);
                }
            }],
            [$end->subDays((int) round(0.65 * $this->days))->setTime(10, 0)->getTimestamp(), function () use ($admin, $game, $first, $traffic, &$second): void {
                $traffic->handle($admin, $game->fresh() ?? $game, GameAudience::Anonymous, [$first => 30, (int) $second => 70]);
            }],
        ];

        if ($network !== null) {
            $this->moments[] = [$end->subDays((int) round(0.23 * $this->days))->setTime(10, 0)->getTimestamp(), function () use ($admin, $game, $first, $traffic, $network, &$second): void {
                $traffic->handle($admin, $game->fresh() ?? $game, GameAudience::Anonymous, [$first => 70, (int) $second => 30], scope: TrafficScope::source($network));
            }];
        }
    }

    /** The game's versions and the traffic periods in force, read again after a change. */
    private function readGame(): void
    {
        $this->versions = [];
        $this->periods = ['anonymous' => [], 'authenticated' => [], 'sources' => []];
        $versions = GameVersion::query()->where('game_id', $this->gameId)->orderBy('id')->get();

        foreach ($versions as $version) {
            $this->versions[(int) $version->id] = [
                'rounds' => max(1, (int) $version->round_count),
                'maxScore' => (int) $version->max_score,
                // The flow's goal reached with the account, or else — near enough for load — with the result.
                'accountGoal' => in_array($version->goal, [GameFlowGoal::Account, GameFlowGoal::NewAccount], true),
                'worse' => (int) $version->id !== (int) $versions->first()?->id,
            ];
        }

        $this->versionIds = array_keys($this->versions);

        foreach (GameTrafficPeriod::query()->with('versions')->where('game_id', $this->gameId)->orderBy('started_at')->orderBy('id')->get() as $period) {
            $entry = [$period->started_at->getTimestamp(), (int) $period->id, $period->shares()];

            if ($period->source_id !== null) {
                $this->periods['sources'][(int) $period->source_id][] = $entry;
            } else {
                $this->periods[$period->audience->value][] = $entry;
            }
        }
    }

    /**
     * Campaigns enough for each ad network to look like one at work — the
     * first a few more than the others — some begun later than others; and
     * those it had, begun at the start.
     *
     * @param  list<TrafficSource>  $sources
     */
    private function campaigns(array $sources, CarbonImmutable $start): void
    {
        $wanted = 7;
        $span = 2 * $this->days;

        foreach ($sources as $source) {
            if ($source->type !== TrafficSourceType::Network) {
                continue;
            }

            TrafficCampaign::query()->where('source_id', $source->id)->where('first_click_at', '>', $start)->update(['first_click_at' => $start]);

            for ($have = TrafficCampaign::query()->where('source_id', $source->id)->count(); $have < $wanted; $have++) {
                $begins = $start->addDays((int) round([0, 0.1, 0.25, 0.45, 0.6, 0.75, 0.9][$have] * $span))->setTime(9, 0);
                TrafficCampaign::query()->forceCreate([
                    'source_id' => $source->id,
                    'external_id' => (string) (6_100_000 + (int) $source->id * 1000 + $have),
                    'status' => TrafficCampaignStatus::Active,
                    'first_click_at' => $begins,
                    'created_at' => $begins,
                    'updated_at' => $begins,
                ]);
            }

            $wanted = 5;
        }
    }

    /** A tracking link for each set of tags the tagged arrivals come by, made in its drawer. */
    private function links(User $admin, Game $game): void
    {
        $save = app(SaveTrackingLinkAction::class);

        foreach (self::ARRIVALS as [, , , $tags, $lands, $varying]) {
            if ($tags === []) {
                continue;
            }

            $tag = array_key_first($varying);

            foreach ($tag === null ? [null] : $varying[$tag] as $value) {
                $known = array_key_exists($tags['utm_source'], ['instagram' => 1, 'facebook' => 1, 'reddit' => 1, 'google' => 1, 'email' => 1]);
                $save->handle($admin, null, [
                    ...(array_key_first($lands) === 'play' ? ['destination' => 'game', 'game' => $game->id] : ['destination' => 'page', 'page' => '/']),
                    'source' => $known ? $tags['utm_source'] : 'other',
                    'source_custom' => $known ? '' : $tags['utm_source'],
                    'medium' => $tags['utm_medium'],
                    'campaign' => $tags['utm_campaign'],
                    'content' => $tag === 'utm_content' ? $value : '',
                    'term' => $tag === 'utm_term' ? $value : '',
                ]);
            }
        }
    }

    /** Two segments of the first ad network, made in their drawer. */
    private function segments(User $admin, int $sourceId): void
    {
        $save = app(SaveTrafficSegmentAction::class);
        $save->handle($admin, $sourceId, null, ['name' => 'Phones in tier 1', 'conditions' => [
            ['parameter' => 'device', 'operator' => 'in', 'values' => 'mobile'],
            ['parameter' => 'country', 'operator' => 'in', 'values' => 'US, GB, CA, AU, DE'],
        ]]);
        $save->handle($admin, $sourceId, null, ['name' => 'Desktop', 'conditions' => [
            ['parameter' => 'device', 'operator' => 'in', 'values' => 'desktop'],
        ]]);
    }

    /** What every visit is written from: the slots that pick, the groups of the countries, the members, the first ids. */
    private function prepare(): void
    {
        $prices = TrafficPriceSet::current();
        $this->priceSet = (int) $prices->id;

        foreach (self::PLACES as $code => [$weight, $cities]) {
            $this->countryCodes[] = $code;
            $this->groups[$code] = $prices->groupOf($code === '' ? null : $code);
            $citySlots = [];

            foreach ($cities as $index => [, $share]) {
                array_push($citySlots, ...array_fill(0, $share, (string) $index));
            }

            $this->slots['city:'.$code] = [...$citySlots, ...array_fill(0, max(0, 100 - count($citySlots)), '')];
        }

        $this->slots['country'] = self::slots(array_combine(array_keys($this->countryCodes), array_column(self::PLACES, 0)));
        $this->slots['arrival'] = self::slots(array_map(fn (array $arrival): int => $arrival[0], self::ARRIVALS));
        $this->slots['member'] = self::slots(self::MEMBER_ARRIVALS);

        foreach (self::ARRIVALS as $key => $arrival) {
            $this->slots['lands:'.$key] = self::slots($arrival[4]);
        }

        $this->members = User::query()->where('role', UserRole::User)->whereNotNull('email_verified_at')->orderBy('id')->limit(400)->pluck('id')
            ->map(fn (mixed $id): array => [(int) $id, hash('sha256', 'volume-member-'.$id)])->all();

        $this->blank = array_fill_keys([
            'channel', 'source_id', 'campaign_id', 'site_ref', 'zone_ref', 'creative_ref', 'origin', 'click_hash', 'visitor_hash', 'known', 'known_reason',
            'device', 'country', 'region', 'city', 'game_id', 'version_id', 'price_set_id', 'country_group', 'worth_percent', 'bot_flags', 'arrived_at',
            'network_day', 'holds_until', 'last_seen_at', 'opened_at', 'started_at', 'halfway_at', 'completed_at', 'goal_at', 'shared_at', 'replayed_at',
            'post_opened_at', 'feed_at', 'signup_form_at', 'signup_at', 'account_at', 'returned_at', 'settled_at', 'signup_place', 'signup_method',
            'signup_after_seconds', 'account_after_seconds', 'rounds_answered', 'cost', 'spend', 'spend_basis', 'spend_estimated', 'value_real',
            'value_expected', 'activated_at', 'entry_visit_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key', 'utm_source', 'utm_medium',
            'utm_campaign', 'utm_content', 'utm_term', 'votes', 'comments', 'posts_created', 'acted_at',
        ], null);
        $this->blank = [...$this->blank, 'price_set_id' => $this->priceSet, 'bot_flags' => 0, 'known' => false, 'rounds_answered' => 0, 'spend' => '0',
            'spend_basis' => TrafficSpendBasis::None->value, 'spend_estimated' => false, 'value_real' => '0', 'value_expected' => '0', 'votes' => 0, 'comments' => 0, 'posts_created' => 0];

        // The first id each table hands out next: a row made and taken back, so the ids after it follow one another.
        $at = self::moment($this->now);
        $period = $this->periods['anonymous'][0][1];
        $this->nextVisit = self::probe(TrafficVisit::class, ['channel' => TrafficChannel::Direct->value, 'visitor_hash' => str_repeat('0', 64), 'device' => 'mobile', 'price_set_id' => $this->priceSet,
            'country_group' => 'rest', 'worth_percent' => 100, 'arrived_at' => $at, 'network_day' => substr($at, 0, 10), 'last_seen_at' => $at]);
        $this->nextAssignment = $this->firstAssignment = self::probe(GameAssignment::class, ['game_id' => $this->gameId, 'audience' => GameAudience::Anonymous->value,
            'visitor_hash' => str_repeat('0', 64), 'version_id' => $this->versionIds[0], 'period_id' => $period, 'assigned_at' => $at, 'last_seen_at' => $at]);
        $this->nextAttempt = self::probe(GameAttempt::class, ['public_id' => str_repeat('0', 26), 'game_id' => $this->gameId, 'version_id' => $this->versionIds[0],
            'audience' => GameAudience::Anonymous->value, 'max_score' => 0, 'locale' => 'en', 'started_at' => $at, 'last_activity_at' => $at]);
    }

    /**
     * Every day of both windows, the oldest first: the visits each brought,
     * the site growing day by day, a little more at weekends; today's only
     * as far as now.
     */
    private function history(int $start, int $today): void
    {
        $count = 2 * $this->days;
        $weights = [];

        for ($day = 0; $day < $count; $day++) {
            $date = $start + $day * 86400;
            $weekend = (int) gmdate('N', $date) >= 6;
            $weights[$day] = (0.65 + 0.35 * $day / max(1, $count - 1)) * ($weekend ? 1.12 : 1.0) * (mt_rand(92, 108) / 100);
        }

        // Today has had only its hours so far.
        $weights[$count - 1] *= max(0.01, ($this->now - $today) / 86400);
        $scale = $this->visits / array_sum(array_slice($weights, $this->days));

        foreach ($weights as $day => $weight) {
            $this->day($start + $day * 86400, (int) round($weight * $scale * ($day < $this->days ? $this->before : 1.0)));
            $this->command?->getOutput()->write('.');
        }
    }

    /** One day's sessions, in the order they began, until it has the visits it brought. */
    private function day(int $from, int $visits): void
    {
        $until = min($from + 86399, $this->now);
        $times = [];

        for ($i = 0, $sessions = (int) ceil($visits / 1.15) + 8; $i < $sessions; $i++) {
            $times[] = $this->when($from, $until);
        }

        sort($times);
        $made = 0;

        for ($i = 0; $made < $visits; $i++) {
            $at = $times[$i] ?? $this->when($from, $until);

            while ($this->moments !== [] && $this->moments[0][0] <= $at) {
                [$moment, $change] = array_shift($this->moments);
                $this->flush();
                DemoPlaythrough::clock(CarbonImmutable::createFromTimestamp($moment, 'UTC'));
                $change();
                DemoPlaythrough::clock($this->heldClock);
                $this->readGame();
            }

            $roll = mt_rand(1, 100);
            $made += match (true) {
                $roll <= 46 => $this->paidClick($at),
                $roll <= 50 => $this->memberSession($at),
                default => $this->arrival($at),
            };

            if (count($this->rows['visits']) >= self::FLUSH_VISITS) {
                $this->flush();
            }
        }
    }

    /**
     * A paid click: a session of its own, of one visit. Most pages run, some
     * never do, and now and then a crawler makes one; the page that ran is
     * the game's, given the version of the day's split for the source.
     */
    private function paidClick(int $at): int
    {
        $source = $this->sources[$this->sourceSlots[mt_rand(0, count($this->sourceSlots) - 1)]];
        $returning = $this->browsers > 100 && mt_rand(1, 100) <= 8;
        $browser = $returning ? $this->pastBrowser() : ++$this->browsers;
        $hash = hash('sha256', 'volume-browser-'.$browser);
        $campaign = $this->campaignOf($source, $at);
        $crawler = mt_rand(1, 1000) <= 25;
        $opened = ! $crawler && mt_rand(1, 100) <= 72;
        $site = $source['network'] ? 1 + (int) (3000 * (mt_rand() / mt_getrandmax()) ** 2.5) : null;
        $creative = match (true) {
            $source['creatives'] && $campaign !== null => (string) (700_000 + $campaign * 10 + mt_rand(1, 3)),
            ! $source['network'] => ['sidebar-300x250', 'footer-728x90'][mt_rand(0, 1)],
            default => null,
        };
        $id = $this->nextVisit++;
        $row = [...$this->blank, ...$this->place($browser)];
        $group = $this->groups[$row['country'] ?? ''];
        $cost = $source['network'] ? round((0.6 + 1.4 * $group['percent'] / 100) * 0.001 * $source['rate'] * mt_rand(80, 120) / 100, 8) : null;
        $offset = $source['timezone'] === null ? 0 : $source['timezone']->getOffset(new DateTimeImmutable('@'.$at));

        $row = [
            ...$row,
            'channel' => $source['network'] ? TrafficChannel::Network->value : TrafficChannel::Placement->value,
            'source_id' => $source['id'],
            'campaign_id' => $campaign,
            'site_ref' => $site === null ? null : (string) $site,
            'zone_ref' => $site === null || ! $source['creatives'] ? null : (string) ($site * 10 + mt_rand(0, 3)),
            'creative_ref' => $creative,
            'click_hash' => hash('sha256', 'volume-click-'.++$this->clicks),
            'visitor_hash' => $hash,
            'known' => $returning,
            'known_reason' => $returning ? TrafficKnownReason::Browser->value : null,
            'bot_flags' => $crawler ? BotSignals::CRAWLER : 0,
            'arrived_at' => self::moment($at),
            'network_day' => gmdate('Y-m-d', $at + $offset),
            'holds_until' => self::moment($at + $source['days'] * 86400),
            'last_seen_at' => self::moment($at),
            'cost' => $cost === null ? null : number_format($cost, 8, '.', ''),
            'spend' => number_format($cost ?? 0.0007, 8, '.', ''),
            'spend_basis' => $source['basis'],
            'entry_visit_id' => $id,
            'source_key' => AnalyticsSource::paid($source['id']),
            'campaign_key' => CampaignKey::paid($campaign),
            'creative_key' => $creative,
            // The game's page names its game on the click, before it runs; its version only once it has.
            'game_id' => $this->gameId,
            'landing_key' => LandingRoute::key('games.play', $this->gameId, null),
        ];

        if ($opened) {
            $row['opened_at'] = self::moment($at + mt_rand(1, 6));
            $this->onGame($row, $id, $browser, null, $hash, $at, 'paid', ! $returning, $source['id']);
            $row['landing_key'] = LandingRoute::key('games.play', $this->gameId, $row['version_id']);
            $settles = min($at + 86400, strtotime((string) $row['last_seen_at'].' UTC') + 1800);

            if ($settles <= $this->now) {
                $row['settled_at'] = self::moment($row['account_at'] === null ? $settles : min($settles, strtotime((string) $row['account_at'].' UTC')));
            }
        }

        $this->rows['visits'][] = $row;
        $this->counts['visits']++;

        return 1;
    }

    /**
     * An unpaid arrival from a browser new or seen before: the session's
     * first visit — the feed, a post, the game or a shared result — and
     * perhaps a visit to the rest of the site or to the game after it.
     */
    private function arrival(int $at): int
    {
        $key = $this->pick('arrival');
        $returning = $this->browsers > 100 && mt_rand(1, 100) <= 25;
        $browser = $returning ? $this->pastBrowser() : ++$this->browsers;

        return $this->session($key, $at, $browser, hash('sha256', 'volume-browser-'.$browser), $returning ? TrafficKnownReason::Browser : null, null);
    }

    /** A member of the site, signed in, from the feed mostly. */
    private function memberSession(int $at): int
    {
        if ($this->members === []) {
            return $this->arrival($at);
        }

        [$userId, $hash] = $this->members[mt_rand(0, count($this->members) - 1)];

        return $this->session($this->pick('member'), $at, null, $hash, TrafficKnownReason::SignedIn, $userId);
    }

    /**
     * A session: its first visit, keyed as capture keys an arrival, and the
     * visits it went on to — the game from the site's pages, the site's
     * pages from the game — each carrying the first's keys and id.
     */
    private function session(string $key, int $at, ?int $browser, string $hash, ?TrafficKnownReason $known, ?int $userId): int
    {
        [, $channel, $host] = self::ARRIVALS[$key];
        $lands = $this->pick('lands:'.$key);
        $host = match ($host) {
            'tail' => 'blog'.(1 + (int) (400 * (mt_rand() / mt_getrandmax()) ** 2)).'.example',
            'own' => $this->host,
            default => $host,
        };
        $entry = $this->entryKeys($key, $channel, $host);
        $place = $browser === null ? $this->memberPlace((int) $userId) : $this->place($browser);
        $id = $this->nextVisit++;
        $game = in_array($lands, ['play', 'result'], true);
        $row = [
            ...$this->blank,
            ...$place,
            ...$entry,
            'channel' => $channel,
            'origin' => $host,
            'visitor_hash' => $hash,
            'known' => $known !== null,
            'known_reason' => $known?->value,
            'arrived_at' => self::moment($at),
            'network_day' => gmdate('Y-m-d', $at),
            'last_seen_at' => self::moment($at),
            'opened_at' => self::moment($at),
            'entry_visit_id' => $id,
        ];
        $new = $known === null;

        if ($game) {
            $this->onGame($row, $id, $browser, $userId, $hash, $at, $lands === 'result' ? 'shared' : 'entry', $new, null);
            $row['landing_key'] = LandingRoute::key($lands === 'result' ? 'games.result' : 'games.play', $this->gameId, $row['version_id']);
        } else {
            $row['landing_key'] = LandingRoute::key($lands === 'post' ? 'posts.show' : 'feed', null, null);
            $this->onSite($row, $id, $at, $lands === 'post', $new, $userId);
        }

        $this->rows['visits'][] = $row;
        $this->counts['visits']++;

        // On to the game from the site's pages, or to the site's pages from the game: a visit of the same session.
        if (mt_rand(1, 100) > ($game ? 25 : ($userId === null ? 20 : 14))) {
            return 1;
        }

        $next = strtotime((string) $row['last_seen_at'].' UTC') + mt_rand(15, 240);

        if ($next > $this->now) {
            return 1;
        }

        $second = [
            ...$row,
            ...array_intersect_key($this->blank, array_flip(['game_id', 'version_id', 'started_at', 'halfway_at', 'completed_at', 'goal_at', 'shared_at', 'replayed_at',
                'post_opened_at', 'feed_at', 'signup_form_at', 'signup_at', 'account_at', 'returned_at', 'signup_place', 'signup_method', 'signup_after_seconds',
                'account_after_seconds', 'rounds_answered', 'activated_at', 'votes', 'comments', 'posts_created', 'acted_at'])),
            'channel' => TrafficChannel::Internal->value,
            'origin' => $game ? 'game' : 'feed',
            'arrived_at' => self::moment($next),
            'network_day' => gmdate('Y-m-d', $next),
            'last_seen_at' => self::moment($next),
            'opened_at' => self::moment($next),
        ];
        // One account a browser: who signed up on the first visit doesn't again.
        $new = $new && $row['signup_at'] === null;
        $secondId = $this->nextVisit++;

        if ($game) {
            $this->onSite($second, $secondId, $next, false, $new, $userId);
        } else {
            $this->onGame($second, $secondId, $browser, $userId, $hash, $next, 'internal', $new, null);
        }

        $this->rows['visits'][] = $second;
        $this->counts['visits']++;

        return 2;
    }

    /**
     * A visit to the game's page that ran: the version the browser was
     * given, the attempt and how far it went, the result seen and shared,
     * the ways on, and — for a new visitor — the sign-up form, the sign-up
     * and the account.
     *
     * @param  array<string, mixed>  $row
     */
    private function onGame(array &$row, int $visitId, ?int $browser, ?int $userId, string $hash, int $at, string $kind, bool $new, ?int $sourceId): void
    {
        $anonymous = $userId === null;
        // Before the days the game's own rows are written for, only the visit says what was played.
        [$assignment, $version] = $anonymous ? $this->assignment((int) $browser, $hash, $at, $sourceId) : $this->memberAssignment($userId, $at);
        $about = $this->versions[$version];
        $audience = $anonymous ? GameAudience::Anonymous->value : GameAudience::Authenticated->value;
        $row['game_id'] = $this->gameId;
        $row['version_id'] = $version;
        $this->event(GameEventType::View, $version, null, $assignment, $audience, $userId, $hash, $at);

        $starts = ['paid' => 50, 'entry' => 62, 'shared' => 55, 'internal' => 72][$kind];
        $last = $at + mt_rand(5, 60);

        if (mt_rand(1, 100) > ($anonymous ? $starts : 70)) {
            if ($new && mt_rand(1, 100) === 1) {
                $this->signUp($row, $visitId, null, $hash, $at, $last, AccountSignupPlace::Game, $kind === 'paid', $about['worse']);
            }

            $row['last_seen_at'] = self::moment(max($last, strtotime((string) $row['last_seen_at'].' UTC')));

            return;
        }

        $started = $at + mt_rand(4, 40);
        $rounds = $about['rounds'];
        $completed = mt_rand(1, 100) <= 64;
        $answered = $completed ? $rounds : mt_rand(0, $rounds - 1);
        $finished = $started + $rounds * mt_rand(4, 14);

        // A play that would end after now is still going: no result seen yet,
        // so it is neither completed nor written a result event.
        if ($completed && $finished + 1 > $this->now) {
            $completed = false;
            $answered = $rounds - 1;
        }

        $attemptId = $assignment === null ? null : $this->nextAttempt++;
        $row['started_at'] = self::moment($started);
        $row['rounds_answered'] = $answered;
        $row['halfway_at'] = $answered * 2 >= $rounds ? self::moment($started + intdiv($finished - $started, 2)) : null;
        $last = $completed ? $finished + mt_rand(10, 90) : $started + $answered * 10 + mt_rand(5, 30);
        $matches = $completed ? mt_rand(0, $rounds) : null;

        if ($completed) {
            $row['completed_at'] = self::moment($finished);
            $this->event(GameEventType::ResultViewed, $version, $attemptId, $assignment, $audience, $userId, $hash, $finished + 1);

            if (! $about['accountGoal']) {
                $row['goal_at'] = self::moment($finished);
            }

            if (mt_rand(1, 100) <= 4) {
                $row['shared_at'] = self::moment($finished + mt_rand(5, 40));
                $this->event(GameEventType::ShareClicked, $version, $attemptId, $assignment, $audience, $userId, $hash, $finished + mt_rand(5, 40), mt_rand(1, 100) <= 60 ? 'copy_link' : 'native');
            }

            $row['replayed_at'] = mt_rand(1, 100) <= 5 ? self::moment($finished + mt_rand(20, 60)) : null;
            $row['feed_at'] = mt_rand(1, 100) <= 8 ? self::moment($finished + mt_rand(20, 90)) : null;
            $row['post_opened_at'] = mt_rand(1, 100) <= 5 ? self::moment($finished + mt_rand(20, 90)) : null;
        }

        $account = null;

        if ($new && mt_rand(1, 1000) <= ($completed ? 120 : ($answered > 0 ? 30 : 10))) {
            $account = $this->signUp($row, $visitId, $attemptId, $hash, $at, $last, AccountSignupPlace::Game, $kind === 'paid', $about['worse']);

            if ($row['account_at'] !== null && $about['accountGoal']) {
                $row['goal_at'] = $row['account_at'];
            }
        }

        if ($attemptId === null) {
            $row['last_seen_at'] = self::moment(min($this->now, max($last, strtotime((string) $row['last_seen_at'].' UTC'))));

            return;
        }

        $this->rows['attempts'][] = [
            'public_id' => 'VOLUME'.str_pad((string) $attemptId, 20, '0', STR_PAD_LEFT),
            'game_id' => $this->gameId,
            'version_id' => $version,
            'assignment_id' => $assignment,
            'audience' => $audience,
            'user_id' => $userId,
            'visitor_hash' => $anonymous ? $hash : null,
            'status' => $completed ? GameAttemptStatus::Completed->value : GameAttemptStatus::Started->value,
            'rounds_answered' => $answered,
            'matches' => $matches,
            'score' => $matches,
            'max_score' => $about['maxScore'],
            'locale' => 'en',
            'account_outcome' => $account === null ? null : 'registered',
            'account_outcome_at' => $account === null ? null : self::moment($account),
            'started_at' => self::moment($started),
            'completed_at' => $completed ? self::moment($finished) : null,
            'last_activity_at' => self::moment(min($last, $this->now)),
            'created_at' => self::moment($started),
            'updated_at' => self::moment(min($last, $this->now)),
            'goal_reached_at' => $row['goal_at'],
            'traffic_visit_id' => $visitId,
        ];
        $this->counts['attempts']++;
        $row['last_seen_at'] = self::moment(min($this->now, max($last, strtotime((string) $row['last_seen_at'].' UTC'))));
    }

    /**
     * A visit to the site's own pages: the post landed on and the posts read
     * after it — those of the last 90 days kept post by post — votes and
     * comments for a member, and for a new visitor now and then the sign-up
     * form, a sign-up and an account.
     *
     * @param  array<string, mixed>  $row
     */
    private function onSite(array &$row, int $visitId, int $at, bool $onPost, bool $new, ?int $userId): void
    {
        $reads = [0, 0, 1, 1, 1, 2, 2, 3, 3, 4, 5, 6][mt_rand(0, 11)] + ($onPost ? 1 : 0);
        $seen = [];
        $moment = $at;
        $votes = 0;
        $comments = 0;
        // When the member first voted or commented, as live capture keeps it: three seconds into the post, before the visit is last seen.
        $acted = null;

        for ($read = 0; $read < $reads; $read++) {
            $post = $this->posts[(int) (count($this->posts) * (mt_rand() / (mt_getrandmax() + 1)) ** 2)];
            $moment += mt_rand(15, 150);

            if (isset($seen[$post]) || $moment > $this->now) {
                continue;
            }

            $voted = $userId !== null && mt_rand(1, 100) <= 40;
            $commented = $userId !== null && mt_rand(1, 100) <= 10;
            $votes += $voted ? 1 : 0;
            $comments += $commented ? 1 : 0;
            $acted ??= $voted || $commented ? min($this->now, $moment + 3) : null;
            $seen[$post] = true;

            if ($at >= $this->postsFrom) {
                $this->rows['posts'][] = [
                    'visit_id' => $visitId,
                    'post_id' => $post,
                    'views' => [1, 1, 1, 1, 1, 1, 1, 2, 2, 3][mt_rand(0, 9)],
                    'shared_at' => mt_rand(1, 100) <= 4 ? self::moment($moment + 5) : null,
                    'voted' => $voted,
                    'commented' => $commented,
                    'first_at' => self::moment($moment),
                ];
                $this->counts['post rows']++;
            }
        }

        $row['votes'] = $votes;
        $row['comments'] = $comments;
        $row['posts_created'] = $userId !== null && mt_rand(1, 100) <= 3 ? 1 : 0;
        $acted ??= $row['posts_created'] > 0 ? min($this->now, $moment) : null;
        $row['acted_at'] = $acted === null ? null : self::moment($acted);
        $row['last_seen_at'] = self::moment(min($this->now, $moment + mt_rand(5, 60)));

        if ($new && mt_rand(1, 1000) <= 22) {
            $this->signUp($row, $visitId, null, (string) $row['visitor_hash'], $at, $moment, $onPost ? AccountSignupPlace::Post : AccountSignupPlace::Feed, false, false);
        }
    }

    /**
     * The sign-up form shown on the visit, a sign-up from it now and then —
     * through Google or Facebook, confirmed there and then, or by email,
     * confirmed minutes later or never — and the account, with the days it
     * came back on. An account never confirmed is only there while it is
     * younger than the week the nightly cleanup gives it. Returns when the
     * account signed up, if it did.
     *
     * @param  array<string, mixed>  $row
     */
    private function signUp(array &$row, int $visitId, ?int $attemptId, string $hash, int $arrived, int $at, AccountSignupPlace $place, bool $paid, bool $worse): ?int
    {
        $form = $at + mt_rand(5, 40);

        if ($form > $this->now) {
            return null;
        }

        $row['signup_form_at'] = self::moment($form);

        // Most who see the form close it; paid traffic and the second version a little more often.
        if (mt_rand(1, 1000) > (int) (400 * ($paid ? 0.75 : 1.0) * ($worse ? 0.85 : 1.0))) {
            return null;
        }

        $signedUp = $form + mt_rand(20, 90);

        if ($signedUp > $this->now) {
            return null;
        }

        $method = mt_rand(1, 100) <= 60 ? AccountSignupMethod::Email : (mt_rand(1, 100) <= 70 ? AccountSignupMethod::Google : AccountSignupMethod::Facebook);
        $confirmed = $method !== AccountSignupMethod::Email ? $signedUp : (mt_rand(1, 100) <= 68 ? $signedUp + mt_rand(60, 2400) : null);
        $confirmed = $confirmed !== null && $confirmed <= $this->now ? $confirmed : null;

        $row['signup_at'] = self::moment($signedUp);
        $row['signup_place'] = $place->value;
        $row['signup_method'] = $method->value;
        $row['signup_after_seconds'] = $signedUp - $arrived;

        if ($confirmed === null && $signedUp < $this->now - 7 * 86400) {
            return $signedUp;
        }

        $n = ++$this->accountsMade;
        $days = $confirmed === null ? [] : $this->daysBack($signedUp, $place === AccountSignupPlace::Game);
        $activated = null;
        $returned = null;

        foreach ($days as $day) {
            $returned ??= $day['day_number'] >= 1 ? $day['first_at'] : null;
            $activated ??= $day['day_number'] >= 1 && $day['day_number'] <= (int) config('analytics.activation_days') ? $day['first_at'] : null;
        }

        if ($confirmed !== null) {
            $row['account_at'] = self::moment($confirmed);
            $row['account_after_seconds'] = $confirmed - $arrived;
            $row['activated_at'] = $activated;
            $row['returned_at'] = $returned;
        }

        $email = 'volume.'.$n.self::EMAIL_DOMAIN;
        $this->accounts[] = [
            'user' => [
                'name' => 'Volume Reader '.$n,
                'username' => 'volume_reader_'.$n,
                'email' => $email,
                'password' => $this->password,
                'email_verified_at' => $confirmed === null ? null : self::moment($confirmed),
                'role' => UserRole::User->value,
                'status' => UserStatus::Active->value,
                'trust_level' => 0,
                'created_at' => self::moment($signedUp),
                'updated_at' => self::moment($confirmed ?? $signedUp),
            ],
            'attribution' => [
                'user_id' => null,
                'visitor_hash' => $hash,
                'signup_method' => $method->value,
                'signup_place' => $place->value,
                'signed_up_at' => self::moment($signedUp),
                'confirmed_at' => $confirmed === null ? null : self::moment($confirmed),
                'credited_attempt_id' => $attemptId,
                'credited_visit_id' => $visitId,
                // What Retention reads of them, copied as RecordAccountSignupAction copies it.
                'credited_source_key' => $row['source_key'] ?? null,
                'credited_device' => $row['device'],
                'credited_game_id' => $attemptId === null ? null : $this->gameId,
                'late' => false,
                'returned_at' => $returned,
                'activated_at' => $activated,
                'created_at' => self::moment($signedUp),
                'updated_at' => self::moment($confirmed ?? $signedUp),
            ],
            'browser' => ['user_id' => null, 'visitor_hash' => $hash, 'linked_at' => self::moment($signedUp)],
            'days' => $days,
            'member' => $confirmed === null ? null : $hash,
        ];

        return $signedUp;
    }

    /**
     * The days a new account did something: the day it signed up when it
     * played, then most of those who come back within the first week —
     * which activates them — some later in the month, and plenty never.
     *
     * @return list<array<string, mixed>>
     */
    private function daysBack(int $signedUp, bool $played): array
    {
        $first = intdiv($signedUp, 86400) * 86400;
        $days = $played ? [self::activityDay(0, $signedUp, ['played' => true])] : [];

        foreach ([1 => 30, 2 => 18, 3 => 14, 4 => 10, 5 => 11, 6 => 8, 7 => 9, 10 => 8, 14 => 8, 21 => 6, 30 => 5] as $number => $chance) {
            $at = $first + $number * 86400 + self::daytime();

            if (mt_rand(1, 100) <= $chance && $at <= $this->now) {
                $voted = mt_rand(1, 100) <= 60;
                $days[] = self::activityDay($number, $at, ['voted' => $voted, 'commented' => mt_rand(1, 100) <= 20, 'posted' => mt_rand(1, 100) <= 5, 'played' => ! $voted || mt_rand(1, 100) <= 30]);
            }
        }

        return $days;
    }

    /**
     * The browser's assignment of the game: kept from its first game visit
     * on, as a version keeps its visitors while it has a share; else a new
     * one by the split in force — the source's own, else everyone's.
     *
     * @return array{0: int|null, 1: int} the assignment's id — none before the days the game's rows are written for — and its version
     */
    private function assignment(int $browser, string $hash, int $at, ?int $sourceId): array
    {
        $offset = 4 * $browser;

        // Grown by doubling, so the browsers' bytes are copied only a few times however many come.
        if (strlen($this->assigned) < $offset + 4) {
            $this->assigned .= str_repeat("\0", max(4 * 65536, strlen($this->assigned), $offset + 4 - strlen($this->assigned)));
        }

        if (strlen($this->assignedVersion) < $browser + 1) {
            $this->assignedVersion .= str_repeat("\0", max(65536, strlen($this->assignedVersion), $browser + 1 - strlen($this->assignedVersion)));
        }

        $kept = unpack('V', substr($this->assigned, $offset, 4));
        $keptVersion = ord($this->assignedVersion[$browser]);

        if ($kept !== false && $kept[1] > 0) {
            return [$this->firstAssignment + $kept[1] - 1, $this->versionIds[$keptVersion - 1]];
        }

        $split = $sourceId !== null && isset($this->periods['sources'][$sourceId]) ? self::inForce($this->periods['sources'][$sourceId], $at) : null;
        [$period, $shares] = $split ?? self::inForce($this->periods['anonymous'], $at) ?? [$this->periods['anonymous'][0][1], $this->periods['anonymous'][0][2]];
        $version = $keptVersion > 0 ? $this->versionIds[$keptVersion - 1] : self::chosen($shares);
        $this->assignedVersion[$browser] = chr((int) array_search($version, $this->versionIds, true) + 1);

        if ($at < $this->gameFrom) {
            return [null, $version];
        }

        $id = $this->assign(GameAudience::Anonymous, null, $hash, $version, $period, $at);
        $packed = pack('V', $id - $this->firstAssignment + 1);

        for ($byte = 0; $byte < 4; $byte++) {
            $this->assigned[$offset + $byte] = $packed[$byte];
        }

        return [$id, $version];
    }

    /**
     * A member's assignment, kept the same way, by the split for players
     * signed in.
     *
     * @return array{0: int|null, 1: int}
     */
    private function memberAssignment(int $userId, int $at): array
    {
        if (($this->memberAssignments[$userId][0] ?? null) === null) {
            [$period, $shares] = self::inForce($this->periods['authenticated'], $at) ?? [$this->periods['authenticated'][0][1], $this->periods['authenticated'][0][2]];
            $version = $this->memberAssignments[$userId][1] ?? self::chosen($shares);
            $this->memberAssignments[$userId] = [$at < $this->gameFrom ? null : $this->assign(GameAudience::Authenticated, $userId, null, $version, $period, $at), $version];
        }

        return $this->memberAssignments[$userId];
    }

    private function assign(GameAudience $audience, ?int $userId, ?string $hash, int $version, int $period, int $at): int
    {
        $this->rows['assignments'][] = [
            'game_id' => $this->gameId,
            'audience' => $audience->value,
            'user_id' => $userId,
            'visitor_hash' => $hash,
            'active_key' => $userId !== null ? 'u:'.$userId : 'v:'.$hash,
            'version_id' => $version,
            'period_id' => $period,
            'assigned_at' => self::moment($at),
            'last_seen_at' => self::moment($at),
            'created_at' => self::moment($at),
            'updated_at' => self::moment($at),
        ];
        $this->counts['assignments']++;

        return $this->nextAssignment++;
    }

    private function event(GameEventType $type, int $version, ?int $attemptId, ?int $assignmentId, string $audience, ?int $userId, string $hash, int $at, ?string $provider = null): void
    {
        if ($assignmentId === null || $at > $this->now) {
            return;
        }

        $this->rows['events'][] = [
            'game_id' => $this->gameId,
            'version_id' => $version,
            'attempt_id' => $attemptId,
            'assignment_id' => $assignmentId,
            'audience' => $audience,
            'user_id' => $userId,
            'visitor_hash' => $userId === null ? $hash : null,
            'type' => $type->value,
            'provider' => $provider,
            'occurred_at' => self::moment($at),
        ];
        $this->counts['events']++;
    }

    /**
     * What a session's arrival writes on each of its visits, as capture
     * writes it: its source by the channel, the referrer and the tags, its
     * campaign by utm_campaign, its creative by utm_content or else
     * utm_term, and the tags themselves.
     *
     * @return array<string, mixed>
     */
    private function entryKeys(string $key, string $channel, ?string $host): array
    {
        $varying = self::ARRIVALS[$key][5];
        $tag = array_key_first($varying);
        $value = $tag === null ? null : $varying[$tag][mt_rand(0, count($varying[$tag]) - 1)];
        $cache = $key.'|'.$host.'|'.$value;

        if (! isset($this->entryKeys[$cache])) {
            $tags = UtmTags::fromQuery([...self::ARRIVALS[$key][3], ...($tag === null ? [] : [$tag => (string) $value])]);
            $this->entryKeys[$cache] = [
                'source_key' => AnalyticsSource::keyFor(TrafficChannel::from($channel), null, $host === $this->host ? null : $host, $tags->source, $tags->medium),
                'campaign_key' => CampaignKey::tag($tags->campaign),
                'creative_key' => $tags->creative(),
                ...$tags->columns(),
            ];
        }

        return $this->entryKeys[$cache];
    }

    /**
     * Where a browser is, the same on every visit: its device, country,
     * region and city, and the worth group of the country.
     *
     * @return array<string, mixed>
     */
    private function place(int $browser): array
    {
        $spread = ($browser * 2654435761) % 4294967296;
        $code = $this->countryCodes[(int) $this->slots['country'][intdiv($spread, 100) % count($this->slots['country'])]];
        $city = $this->slots['city:'.$code][intdiv($spread, 100_000) % 100];
        $where = $city === '' ? null : self::PLACES[$code][1][(int) $city];

        return [
            'device' => $spread % 100 < 64 ? 'mobile' : 'desktop',
            'country' => $code === '' ? null : $code,
            'region' => $where[2] ?? null,
            'city' => $where[0] ?? null,
            'country_group' => $this->groups[$code]['key'],
            'worth_percent' => $this->groups[$code]['percent'],
        ];
    }

    /** @return array<string, mixed> */
    private function memberPlace(int $userId): array
    {
        // Past any browser's number, and small enough that spreading it stays an integer.
        return $this->place(2_000_000_000 + $userId);
    }

    /** A browser seen before: one of the latest third to have come. */
    private function pastBrowser(): int
    {
        return mt_rand(max(1, (int) ($this->browsers * 0.7)), $this->browsers);
    }

    /**
     * The campaign of the source a click came by, of those begun by then,
     * the first ones the busiest; none for a source without campaigns.
     *
     * @param  array{campaigns: list<array{0: int, 1: int}>}  $source
     */
    private function campaignOf(array $source, int $at): ?int
    {
        $begun = array_values(array_filter($source['campaigns'], fn (array $campaign): bool => $campaign[1] <= $at));

        if ($begun === []) {
            return null;
        }

        return $begun[(int) (count($begun) * (mt_rand() / (mt_getrandmax() + 1)) ** 1.6)][0];
    }

    /** A moment of the day given, people's hours mostly; today's never after now. */
    private function when(int $from, int $until): int
    {
        $at = $from + self::daytime();

        return $at <= $until ? $at : $from + mt_rand(0, max(0, $until - $from));
    }

    private function pick(string $slots): string
    {
        return $this->slots[$slots][mt_rand(0, count($this->slots[$slots]) - 1)];
    }

    /**
     * Everything made so far written, each table after those it names: the
     * accounts' members first, then the assignments, the visits, the
     * attempts, the events, the posts read, and the accounts' attributions,
     * days and browsers. The ids each table handed out are checked against
     * those the rows were given.
     */
    private function flush(): void
    {
        DB::transaction(fn () => $this->write());
    }

    private function write(): void
    {
        $attributions = [];
        $days = [];
        $browsers = [];

        if ($this->accounts !== []) {
            self::insert(User::class, array_column($this->accounts, 'user'));
            $ids = [];

            foreach (array_chunk(array_column(array_column($this->accounts, 'user'), 'email'), 1000) as $emails) {
                $ids += User::query()->whereIn('email', $emails)->pluck('id', 'email')->map(fn (mixed $id): int => (int) $id)->all();
            }

            foreach ($this->accounts as $account) {
                $userId = $ids[$account['user']['email']];
                $attributions[] = [...$account['attribution'], 'user_id' => $userId];
                $browsers[] = [...$account['browser'], 'user_id' => $userId];

                foreach ($account['days'] as $day) {
                    $days[] = [...$day, 'user_id' => $userId];
                }

                if ($account['member'] !== null) {
                    $this->members[] = [$userId, $account['member']];
                }
            }

            $this->counts['accounts'] += count($this->accounts);
            $this->counts['activity days'] += count($days);
            $this->accounts = [];
        }

        self::insert(GameAssignment::class, $this->rows['assignments'], $this->nextAssignment - 1);
        self::insert(TrafficVisit::class, $this->rows['visits'], $this->nextVisit - 1);
        self::insert(GameAttempt::class, $this->rows['attempts'], $this->nextAttempt - 1);
        self::insert(GameEvent::class, $this->rows['events']);
        self::insert(TrafficVisitPost::class, $this->rows['posts']);
        self::insert(AccountAttribution::class, $attributions);
        self::insert(UserActivityDay::class, $days);
        self::insert(AccountBrowser::class, $browsers);
        $this->rows = ['visits' => [], 'posts' => [], 'assignments' => [], 'attempts' => [], 'events' => []];
    }

    /**
     * Rows written in chunks under the values a statement may bind; when the
     * last id they were given is named, the table's own is checked against
     * it: rows that name others by id are only right if the ids followed
     * one another as they were handed out.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private static function insert(string $model, array $rows, ?int $lastId = null): void
    {
        if ($rows === []) {
            return;
        }

        $query = $model::query();
        $bound = self::BOUND_VALUES[$query->getModel()->getConnection()->getDriverName()] ?? self::BOUND_VALUES['default'];

        foreach (array_chunk($rows, max(1, intdiv($bound, count($rows[0])))) as $chunk) {
            $model::query()->insert($chunk);
        }

        if ($lastId !== null && (int) $model::query()->max('id') !== $lastId) {
            throw new RuntimeException("The ids of {$query->getModel()->getTable()} didn't follow one another as they were handed out: stop anything else writing to this database and start again from a fresh one.");
        }
    }

    /**
     * The id a table hands out next: a row of it made and taken back.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $row
     */
    private static function probe(string $model, array $row): int
    {
        $id = (int) $model::query()->insertGetId($row);
        $model::query()->whereKey($id)->forceDelete();

        return $id + 1;
    }

    /**
     * The period in force at a moment, of those given from theirs, and its
     * shares; none before the first.
     *
     * @param  list<array{0: int, 1: int, 2: array<int, int>}>  $periods
     * @return array{0: int, 1: array<int, int>}|null
     */
    private static function inForce(array $periods, int $at): ?array
    {
        $found = null;

        foreach ($periods as [$from, $id, $shares]) {
            if ($from <= $at && $shares !== []) {
                $found = [$id, $shares];
            }
        }

        return $found;
    }

    /** @param  array<int, int>  $shares */
    private static function chosen(array $shares): int
    {
        $roll = mt_rand(1, max(1, array_sum($shares)));

        foreach ($shares as $version => $share) {
            $roll -= $share;

            if ($roll <= 0) {
                return $version;
            }
        }

        return (int) array_key_last($shares);
    }

    /**
     * @param  array<string, bool>  $did
     * @return array<string, mixed>
     */
    private static function activityDay(int $number, int $at, array $did): array
    {
        return [
            'user_id' => null,
            'day' => gmdate('Y-m-d', $at),
            'day_number' => $number,
            'voted' => $did['voted'] ?? false,
            'commented' => $did['commented'] ?? false,
            'posted' => $did['posted'] ?? false,
            'played' => $did['played'] ?? false,
            'first_at' => self::moment($at),
        ];
    }

    /**
     * Keys repeated by their weights, a thousand slots in all, for a pick in
     * one step.
     *
     * @param  array<int|string, int>  $weights
     * @return list<string>
     */
    private static function slots(array $weights): array
    {
        $total = max(1, array_sum($weights));
        $slots = [];

        foreach ($weights as $key => $weight) {
            array_push($slots, ...array_fill(0, max(1, (int) round(1000 * $weight / $total)), (string) $key));
        }

        return $slots;
    }

    private static function moment(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }

    /** A moment of the day people read and play: mostly afternoons and evenings, UTC. */
    private static function daytime(): int
    {
        $hour = [0, 1, 6, 7, 8, 9, 10, 11, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 20, 21, 21, 22, 23][mt_rand(0, 23)];

        return $hour * 3600 + mt_rand(0, 3599);
    }
}
