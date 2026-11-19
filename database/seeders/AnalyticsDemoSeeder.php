<?php

namespace Database\Seeders;

use App\Actions\Analytics\RecordUserActivityAction;
use App\Actions\Analytics\RecordVisitPostAction;
use App\Actions\Comments\AddCommentAction;
use App\Actions\Games\AssignGameVersionAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Actions\Games\RecordGameAnswerAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Traffic\OpenTrafficVisitAction;
use App\Actions\Traffic\RecordAccountLoginAction;
use App\Actions\Traffic\RecordAccountSignupAction;
use App\Actions\Traffic\RecordVisitSignalAction;
use App\Actions\Traffic\SaveTrafficSettingsAction;
use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAudience;
use App\Enums\TrafficDevice;
use App\Enums\UserActivity;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\SocialAccount;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Games\Play\GameVisitor;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\ChannelClassifier;
use App\Support\Traffic\Capture\VisitorSignals;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoPlaythrough;
use Database\Seeders\Support\DemoPostMediaGenerator;
use Database\Seeders\Support\DemoTrafficMix;
use Database\Seeders\Support\DemoVisitor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use SplPriorityQueue;
use Throwable;

/**
 * Two months of visitors to the whole site, for Growth → Analytics to show:
 * Record visits and Record site visits switched on, then people arriving on
 * the feed or a post from Reddit — through a tagged thread and plain links —
 * from Google's results and its tagged ads, from Facebook, from the weekly
 * newsletter's tagged links, from a few other sites and from nowhere; reading
 * posts in the overlay, sharing some, going on to the game from the feed;
 * signing up there or on the feed, confirming at once, minutes later or
 * never; and the new accounts coming back on the days after — voting,
 * commenting, posting, playing — which activates some on days 1 to 7. Members
 * of the site come back signed in, too.
 *
 * Every visit is made the way the site makes it: the arrival token the
 * middleware would print, handed to the tracker's own action as the page's
 * script hands it back, so the session, its keys, its tags and its landing
 * are written as live capture writes them; the posts opened and shared, the
 * votes, comments, posts, attempts, sign-ups and confirmations each go
 * through the action the screens call, the clock set back to its moment.
 * Only posts published by then are read, and visitors go on from the feed to
 * the game only from the moment the game's traffic split open now began: the
 * game's page gives the version by the splits open now, never by the one of
 * an earlier day.
 *
 * Needs the large demo dataset, the demo game, the traffic demo and the
 * game's history, in this order; it takes a few minutes on a local
 * PostgreSQL:
 *
 *     php artisan db:seed --class=DemoFillSeeder
 *     php artisan db:seed --class=GamesDemoSeeder
 *     php artisan db:seed --class=TrafficDemoSeeder
 *     php artisan db:seed --class=GameAnalyticsDemoSeeder
 *     php artisan db:seed --class=AnalyticsDemoSeeder
 *
 * Safe to run again: once the site's own pages have visits, nothing is
 * added. Each visit is kept or undone whole, but a run stopped half way
 * leaves half a history: start again from migrate:fresh. Local and testing
 * environments only.
 */
class AnalyticsDemoSeeder extends Seeder
{
    private const int DAYS = 60;

    private const int SEED = 41;

    /** The members of the site who come back signed in. */
    private const int MEMBERS = 80;

    /**
     * Where a new visitor comes from: weight, the referrer its browser
     * sends, the tags of the link it followed, the page it lands on (`post`
     * or `feed`), and the creatives (`utm_content`) the tagged link comes in.
     *
     * @var array<string, array{0: int, 1: string|null, 2: array<string, string>, 3: string, 4?: list<string>}>
     */
    private const array SOURCES = [
        'reddit-thread' => [11, 'https://www.reddit.com/r/FoodPorn/comments/demo_taste_test/', ['utm_source' => 'reddit', 'utm_medium' => 'social', 'utm_campaign' => 'taste-test-thread'], 'post'],
        'reddit' => [10, 'https://out.reddit.com/t3_demo', [], 'post'],
        'google' => [17, 'https://www.google.com/', [], 'feed'],
        'google-ads' => [7, 'https://www.google.com/', ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'brand-search', 'utm_term' => 'rate food photos'], 'feed'],
        'facebook' => [9, 'https://l.facebook.com/', [], 'post'],
        'facebook-promo' => [5, 'https://l.facebook.com/', ['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring-promo'], 'feed', ['carousel-a', 'carousel-b']],
        'direct' => [21, null, [], 'feed'],
        'newsletter' => [8, null, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'weekly-digest'], 'post', ['top-story', 'footer-link']],
        'bing' => [4, 'https://www.bing.com/', [], 'feed'],
        'blog' => [4, 'https://foodblog.example/best-photos-this-week', [], 'post'],
        'forum' => [4, 'https://news.ycombinator.com/item?id=41000001', [], 'feed'],
    ];

    /** Where a member or a returning account comes in from: weight, then as SOURCES. */
    private const array RETURNS = [
        'direct' => 50,
        'google' => 20,
        'newsletter' => 20,
        'reddit' => 10,
    ];

    private const array COMMENTS = ['Looks amazing.', 'I would eat this right now.', 'Not my thing, honestly.', 'How long did this take?', 'The colours are great.', 'Better than the last one.', 'Recipe please!', 'This one wins for me.'];

    private string $host;

    private string $password;

    /** @var list<array{0: int, 1: int}> published posts, by id with the moment each was published, most read first */
    private array $posts = [];

    /** @var array<string, CarbonImmutable|null> when the game's general traffic split open now began, by audience */
    private array $splitsSince = [];

    private int $readers = 0;

    private int $pictures = 0;

    /** @var array<string, int> what was played, for the run's summary */
    private array $counts = ['sessions' => 0, 'post views' => 0, 'shares' => 0, 'games' => 0, 'votes' => 0, 'comments' => 0, 'posts' => 0, 'sign-ups' => 0, 'confirmed' => 0, 'returns' => 0];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::query()->where('email', 'admin@rateguru.test')->first();
        $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->first();

        if ($admin === null || $game === null || ! TrafficSource::query()->where('link_key', 'pop')->exists()
            || (GameVersion::query()->where('game_id', $game->id)->count() < 2 && ! GameAttempt::query()->where('game_id', $game->id)->exists())) {
            $this->command?->warn('Run DemoFillSeeder, GamesDemoSeeder, TrafficDemoSeeder and GameAnalyticsDemoSeeder first; nothing was added.');

            return;
        }

        if (TrafficVisit::query()->whereIn('landing_key', ['feed', 'posts.show'])->exists()) {
            $this->command?->info('The site\'s own pages already have visits; nothing was added. If an earlier run stopped half way, start again from migrate:fresh.');

            return;
        }

        $members = User::query()->where('email', 'like', 'fill%@demo.test')->where('role', UserRole::User)->whereNotNull('email_verified_at')
            ->orderBy('id')->limit(self::MEMBERS)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->posts = Post::query()->published()->orderByDesc('upvotes_count')->orderBy('id')->limit(200)->get(['id', 'published_at'])
            ->map(fn (Post $post): array => [(int) $post->id, $post->published_at?->getTimestamp() ?? 0])->all();

        if ($members === [] || count($this->posts) < 5) {
            $this->command?->warn('Run DemoFillSeeder first: the site needs its members and published posts. Nothing was added.');

            return;
        }

        $this->host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $this->password = Hash::make('password');
        $settings = TrafficSetting::current();
        app(SaveTrafficSettingsAction::class)->handle($admin, ['capture_enabled' => true, 'site_capture_enabled' => true], (int) $settings->lock_version);

        $this->limitInMemory();
        mt_srand(self::SEED);
        $until = CarbonImmutable::now('UTC')->startOfSecond();
        $began = microtime(true);

        try {
            $this->history($game, $members, $until);
        } finally {
            DemoPlaythrough::clock(null);
        }

        $done = collect($this->counts)->map(fn (int $count, string $what): string => number_format($count).' '.$what)->implode(', ');
        $this->command?->info(sprintf('Two months of the site played in %d s: %s.', (int) round(microtime(true) - $began), $done));
    }

    /**
     * Every visit of the two months, each at its moment, in the order they
     * happened: new visitors, members signed in, and the new accounts coming
     * back on the days they come back.
     *
     * @param  list<int>  $members
     */
    private function history(Game $game, array $members, CarbonImmutable $until): void
    {
        $days = $this->days();
        $start = $until->subDays($days)->startOfDay();
        $mix = new DemoTrafficMix(User::query()->where('email', 'admin@rateguru.test')->firstOrFail());
        $queue = new SplPriorityQueue;
        $serial = 0;
        $push = function (CarbonImmutable $at, array $entry) use ($queue, &$serial): void {
            // Earliest first; among equals, in the order queued.
            $queue->insert([...$entry, 'at' => $at], [-$at->getTimestamp(), -++$serial]);
        };
        $weights = array_map(fn (array $source): int => $source[0], self::SOURCES);

        for ($day = 0; $day < $days; $day++) {
            $date = $start->addDays($day);
            $arrivals = (int) round((28 + 36 * $day / $days) * ($date->isWeekend() ? 1.2 : 1.0) * (mt_rand(85, 115) / 100));

            for ($n = 0; $n < $arrivals; $n++) {
                $at = $date->addSeconds(self::daytime());
                $source = array_keys(self::SOURCES)[DemoTrafficMix::weighted(array_values($weights))];
                $visitor = new DemoVisitor(hash('sha256', "demo-site-browser-{$day}-{$n}"), $mix->country(50), mt_rand(1, 100) <= 64 ? TrafficDevice::Mobile : TrafficDevice::Desktop, mt_rand(35, 88) / 100);

                if ($at->lessThanOrEqualTo($until)) {
                    $push($at, ['kind' => 'new', 'visitor' => $visitor, 'source' => $source]);
                }
            }

            for ($n = 0, $sessions = (int) round(3 + 4 * $day / $days); $n < $sessions; $n++) {
                $at = $date->addSeconds(self::daytime());

                if ($at->lessThanOrEqualTo($until)) {
                    $push($at, ['kind' => 'member', 'member' => $members[DemoTrafficMix::skewed(count($members))]]);
                }
            }
        }

        $known = [];
        $today = null;

        while (! $queue->isEmpty()) {
            $entry = $queue->extract();

            // A dot a day played.
            if ($today !== $entry['at']->toDateString()) {
                $today = $entry['at']->toDateString();
                $this->command?->getOutput()->write('.');
            }

            if ($entry['kind'] === 'member') {
                $id = $entry['member'];
                $first = ! isset($known[$id]);
                $known[$id] ??= new DemoVisitor(hash('sha256', 'demo-site-member-'.$id), $mix->country(60), $id % 3 === 0 ? TrafficDevice::Desktop : TrafficDevice::Mobile, mt_rand(50, 90) / 100, memberId: $id);
                $known[$id]->userId = $id;
                DB::transaction(fn () => $this->visit($game, $known[$id], self::returnSource(), $entry['at'], $until, signIn: $first));

                continue;
            }

            $source = $entry['kind'] === 'return' ? self::returnSource() : $entry['source'];
            $backs = DB::transaction(fn (): array => $this->visit($game, $entry['visitor'], $source, $entry['at'], $until));

            foreach ($backs as $back) {
                $push($back, ['kind' => 'return', 'visitor' => $entry['visitor']]);
            }
        }

        $this->command?->newLine();
        DemoPlaythrough::clock($until);
        $this->purgeUnconfirmed();
    }

    /**
     * One visit to the site: the landing page, the posts read in the
     * overlay — voted on, commented on, shared — perhaps the game from the
     * feed, perhaps a sign-up, perhaps a post of one's own. Returns when a
     * new account comes back.
     *
     * @return list<CarbonImmutable>
     */
    private function visit(Game $game, DemoVisitor $visitor, string $source, CarbonImmutable $at, CarbonImmutable $until, bool $signIn = false): array
    {
        DemoPlaythrough::clock($at);
        // Each visit starts with the rate limits empty: their windows never saw a clock that jumps from visit to visit.
        Cache::store('array')->flush();
        $visitor->visits++;
        $this->counts['sessions']++;
        $this->counts['returns'] += $visitor->signedUp ? 1 : 0;
        [, $referrer, $tags, $landing] = self::SOURCES[$source];
        $creatives = self::SOURCES[$source][4] ?? [];
        $tags = $creatives === [] ? $tags : [...$tags, 'utm_content' => $creatives[mt_rand(0, count($creatives) - 1)]];
        $signals = DemoTrafficMix::signals($visitor->country, $visitor->device, ChannelClassifier::host($referrer));
        $user = $visitor->userId === null ? null : User::query()->find($visitor->userId);
        // A post landing with no post published yet lands on the feed.
        $post = $landing === 'post' ? $this->pickPost($at) : null;
        $token = $this->token($referrer, $tags, $post === null ? 'feed' : 'posts.show', $post === null ? '/' : '/posts/'.$post, $post, null, $visitor->browserHash, $at);
        // A member seen for the first time arrives signed out and signs in on the page.
        $visitId = app(OpenTrafficVisitAction::class)->handle($token, $visitor->browserHash, $signIn ? null : $visitor->userId, null, null, $signals, $at);

        if ($visitId === null) {
            return [];
        }

        if ($signIn && $user !== null) {
            $at = $at->addSeconds(mt_rand(10, 40));
            DemoPlaythrough::clock($at);
            app(RecordAccountLoginAction::class)->handle($user, $visitor->browserHash, $at);
        }

        // The post landed on, then the posts read in the overlay.
        if ($post !== null) {
            $this->counts['post views']++;
            $this->onPost($user, $visitor, $post, $at);
        }

        for ($read = 0, $reads = DemoTrafficMix::weighted([22, 26, 20, 13, 9, 6, 4]); $read < $reads; $read++) {
            $at = $at->addSeconds(mt_rand(20, 150));
            DemoPlaythrough::clock($at);
            $post = $this->pickPost($at);

            if ($post === null) {
                continue;
            }

            app(RecordVisitPostAction::class)->viewed($visitor->browserHash, $post, $at);
            $this->counts['post views']++;
            $this->onPost($user, $visitor, $post, $at);
        }

        $played = self::roll($user === null ? 20 : 14) && $this->playFromTheFeed($game, $visitor, $signals, $at);

        if ($user !== null && self::roll($visitor->signedUp ? 8 : 3)) {
            $this->postPicture($user, $visitor, $at = $at->addMinutes(mt_rand(2, 9)));
        }

        if ($user !== null || ! self::roll($played ? 14 : 4)) {
            return [];
        }

        return $this->signUp($visitor, $signals, $at->addMinutes(mt_rand(1, 6)), $played ? AccountSignupPlace::Game : AccountSignupPlace::Feed, $until);
    }

    /** What a reader does on a post they read: a share now and then and, signed in, a vote or a comment. */
    private function onPost(?User $user, DemoVisitor $visitor, int $postId, CarbonImmutable $at): void
    {
        if (self::roll(6)) {
            app(RecordVisitPostAction::class)->shared($visitor->browserHash, $postId, $at->addSeconds(5));
            $this->counts['shares']++;
        }

        if ($user !== null) {
            $this->actOn($user, $visitor, $postId, $at);
        }
    }

    /** A vote on the post now and then — a new one only — and a comment less often. */
    private function actOn(User $user, DemoVisitor $visitor, int $postId, CarbonImmutable $at): void
    {
        $post = Post::query()->find($postId);

        if ($post === null) {
            return;
        }

        if (self::roll(45) && ! PostVote::query()->where('user_id', $user->id)->where('post_id', $postId)->exists()) {
            try {
                app(VotePostAction::class)->handle($user, $post, self::roll(75) ? VoteType::Up : VoteType::Down, $at);
                app(RecordUserActivityAction::class)->quietly($user, UserActivity::Voted, $postId, $visitor->browserHash, $at);
                $this->counts['votes']++;
            } catch (Throwable) {
                // Their own post, or one they may not vote on: no vote, as on the site.
            }
        }

        if (self::roll(12)) {
            try {
                app(AddCommentAction::class)->handle($user, $post, self::COMMENTS[mt_rand(0, count(self::COMMENTS) - 1)], null, $at->addSeconds(40));
                app(RecordUserActivityAction::class)->quietly($user, UserActivity::Commented, $postId, $visitor->browserHash, $at->addSeconds(40));
                $this->counts['comments']++;
            } catch (Throwable) {
                // Refused as the site refuses it: no comment.
            }
        }
    }

    /**
     * On to the game from the feed: a visit inside the site, of the same
     * session, given its version, and an attempt played through or given up.
     * Only from the moment the game's general split open now began: the
     * game's page gives the version by the splits open now, so a visitor
     * before then would be given a version not yet shared out — or not yet
     * published — on the day.
     */
    private function playFromTheFeed(Game $game, DemoVisitor $visitor, VisitorSignals $signals, CarbonImmutable &$at): bool
    {
        $at = $at->addSeconds(mt_rand(15, 90));
        $player = new GameVisitor($visitor->userId === null ? GameAudience::Anonymous : GameAudience::Authenticated, $visitor->userId, $visitor->userId === null ? $visitor->browserHash : null, $visitor->browserHash);
        $since = $this->splitSince($game, $player->audience);

        if ($since === null || $at->lessThan($since)) {
            return false;
        }

        DemoPlaythrough::clock($at);
        $assignment = app(AssignGameVersionAction::class)->handle($game, $player, viewed: true);

        if ($assignment === null) {
            return false;
        }

        $token = $this->token('https://'.$this->host.'/', [], 'games.play', '/games/'.$game->slug, null, $game->slug, $visitor->browserHash, $at);
        $visitId = app(OpenTrafficVisitAction::class)->handle($token, $visitor->browserHash, $visitor->userId, (int) $game->id, (int) $assignment->version_id, $signals, $at);

        if (! self::roll(70)) {
            return true;
        }

        $at = $at->addSeconds(mt_rand(4, 30));
        DemoPlaythrough::clock($at);
        $attempt = app(StartGameAttemptAction::class)->handle($game, $player, 'en', false, $visitId);
        $this->counts['games']++;

        if ($player->isAnonymous()) {
            $visitor->guestAttempts[] = $attempt->public_id;
        }

        $snapshot = $attempt->version->read();

        for ($round = 0; $round < $snapshot->roundCount(); $round++) {
            if ($round > 0 && self::roll(8)) {
                break;
            }

            $at = $at->addSeconds(mt_rand(3, 15));
            DemoPlaythrough::clock($at);
            $answer = $snapshot->round($round)?->answer;
            $position = $answer === null ? mt_rand(0, 1) : (mt_rand(1, 1000) <= (int) ($visitor->taste * 1000) ? $answer : 1 - $answer);
            app(RecordGameAnswerAction::class)->handle($attempt, $player, $round, $position);
        }

        return true;
    }

    /**
     * A new account from this browser, as registering makes one — the form
     * shown first — what it is credited to recorded, its guest attempts its
     * own, confirmed at once
     * through Google or Facebook, by email minutes later, or never. A
     * confirmed account comes back on some of the days after.
     *
     * @return list<CarbonImmutable> when it comes back
     */
    private function signUp(DemoVisitor $visitor, VisitorSignals $signals, CarbonImmutable $at, AccountSignupPlace $place, CarbonImmutable $until): array
    {
        if ($at->greaterThan($until)) {
            return [];
        }

        // The sign-up dialog or page opened first, its script telling the tracker, as on the site.
        $shown = $at->subSeconds(mt_rand(20, 50));
        DemoPlaythrough::clock($shown);
        app(RecordVisitSignalAction::class)->handle('signup_form', null, $visitor->browserHash, null, $signals, $shown);

        DemoPlaythrough::clock($at);
        $method = self::roll(60) ? AccountSignupMethod::Email : (self::roll(70) ? AccountSignupMethod::Google : AccountSignupMethod::Facebook);
        $social = $method !== AccountSignupMethod::Email;
        $tag = substr($visitor->browserHash, 0, 8);
        $this->readers++;

        $user = new User;
        $user->forceFill([
            'name' => 'Reader '.strtoupper($tag),
            'username' => 'reader_'.str_pad((string) $this->readers, 4, '0', STR_PAD_LEFT),
            'email' => 'reader.'.$tag.'@example.com',
            'password' => $this->password,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'trust_level' => 0,
            'email_verified_at' => $social ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        if ($social) {
            SocialAccount::query()->create(['user_id' => $user->id, 'provider' => $method->value, 'provider_user_id' => (string) (8_000_000 + $this->readers), 'provider_email' => $user->email, 'provider_email_verified' => true]);
        }

        app(ClaimGuestGameAttemptsAction::class)->handle($user, $visitor->guestAttempts, GameAccountOutcome::Registered);
        app(RecordAccountSignupAction::class)->handle($user, $visitor->browserHash, $method, $place, $at);
        $visitor->userId = (int) $user->id;
        $visitor->signedUp = true;
        $visitor->guestAttempts = [];
        $this->counts['sign-ups']++;
        $confirmed = $social ? $at : (self::roll(70) ? $at->addMinutes(mt_rand(1, 40)) : null);

        if ($confirmed === null || $confirmed->greaterThan($until)) {
            return [];
        }

        if (! $social) {
            DemoPlaythrough::clock($confirmed);
            $user->forceFill(['email_verified_at' => $confirmed])->save();
        }

        app(ConfirmAccountAttributionAction::class)->handle((int) $user->id, $confirmed);
        $this->counts['confirmed']++;

        return $this->comingBack($confirmed, $until);
    }

    /**
     * The days a new account comes back on: most of those who do within the
     * first week — what activates them — some later in the month, and
     * plenty never.
     *
     * @return list<CarbonImmutable>
     */
    private function comingBack(CarbonImmutable $signedUp, CarbonImmutable $until): array
    {
        $days = [];

        foreach ([1 => 32, 2 => 18, 3 => 14, 5 => 12, 7 => 10, 10 => 9, 14 => 8, 21 => 7, 30 => 6] as $day => $chance) {
            if (self::roll($chance)) {
                $days[] = $signedUp->startOfDay()->addDays($day + mt_rand(0, 1))->addSeconds(self::daytime());
            }
        }

        return array_values(array_filter($days, fn (CarbonImmutable $back): bool => $back->lessThanOrEqualTo($until)));
    }

    /** A post of one's own, with a picture made for it, as the upload form makes one. */
    private function postPicture(User $user, DemoVisitor $visitor, CarbonImmutable $at): void
    {
        DemoPlaythrough::clock($at);
        $this->pictures++;

        try {
            $picture = app(DemoPostMediaGenerator::class)->create('demo/analytics/reader-'.$user->id.'-'.$this->pictures.'.svg', $this->pictures);
            $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
                title: 'My plate, take '.$this->pictures,
                imageAssetId: (int) $picture->id,
                createdAt: $at,
                updatedAt: $at,
            ));
        } catch (Throwable) {
            // Refused as the site refuses it: no post.
            return;
        }

        app(RecordUserActivityAction::class)->quietly($user, UserActivity::Posted, (int) $post->id, $visitor->browserHash, $at);
        $this->counts['posts']++;
    }

    /**
     * The token the middleware prints for a page reached from the given
     * referrer with the given tags, as the page's script hands it back.
     *
     * @param  array<string, string>  $tags
     */
    private function token(?string $referrer, array $tags, string $route, string $path, ?int $postId, ?string $slug, string $browserHash, CarbonImmutable $at): string
    {
        $found = ChannelClassifier::classify($referrer, $this->host, false, $referrer !== null && ChannelClassifier::host($referrer) === $this->host ? 'feed' : null, null);

        return ArrivalToken::make([
            'channel' => $found['channel']->value,
            'origin' => $found['origin'],
            'slug' => $slug,
            'result' => false,
            'route' => $route,
            'path' => $path,
            'host' => ChannelClassifier::host($referrer),
            ...($postId === null ? [] : ['post' => $postId]),
            ...($tags === [] ? [] : ['tags' => $tags]),
        ], $browserHash, $at->getTimestamp());
    }

    /** A post published by the given moment, the most voted on read most; none while none was. */
    private function pickPost(CarbonImmutable $at): ?int
    {
        $published = array_values(array_filter($this->posts, fn (array $post): bool => $post[1] <= $at->getTimestamp()));

        return $published === [] ? null : $published[DemoTrafficMix::skewed(count($published))][0];
    }

    /**
     * When the game's general traffic split open now began for the
     * audience — the split the version is given by, the latest one as
     * GameTrafficQuery::currentPeriods() takes it — or null with none.
     */
    private function splitSince(Game $game, GameAudience $audience): ?CarbonImmutable
    {
        if (! array_key_exists($audience->value, $this->splitsSince)) {
            $this->splitsSince[$audience->value] = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', $audience)
                ->whereNull('source_id')->whereNull('campaign_id')->whereNull('ended_at')->orderByDesc('id')->first(['started_at'])?->started_at;
        }

        return $this->splitsSince[$audience->value];
    }

    /** The demo's new accounts that never confirmed their address within the week, removed as the nightly cleanup removes them. */
    private function purgeUnconfirmed(): void
    {
        $cutoff = UnconfirmedAccountsQuery::cutoff();
        $purge = app(PurgeUnconfirmedAccountAction::class);

        foreach (app(UnconfirmedAccountsQuery::class)->expired($cutoff)->where('username', 'like', 'reader_%')->orderBy('id')->pluck('id') as $id) {
            $purge->handle((int) $id, $cutoff);
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

    /** How many days back the visits go. */
    protected function days(): int
    {
        return self::DAYS;
    }

    /** Where a member or a returning account comes in from. */
    private static function returnSource(): string
    {
        return array_keys(self::RETURNS)[DemoTrafficMix::weighted(array_values(self::RETURNS))];
    }

    /** A moment of the day people read: mostly afternoons and evenings, UTC. */
    private static function daytime(): int
    {
        $hour = [7, 8, 9, 10, 11, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 20, 21, 21, 22, 23][mt_rand(0, 20)];

        return $hour * 3600 + mt_rand(0, 3599);
    }

    private static function roll(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }
}
