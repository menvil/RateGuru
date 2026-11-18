<?php

namespace Database\Seeders\Support;

use App\Actions\Games\AssignGameVersionAction;
use App\Actions\Games\CaptureGameEmailAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Actions\Games\LeaveGameAction;
use App\Actions\Games\MoveGameAttemptAction;
use App\Actions\Games\RecordGameAnswerAction;
use App\Actions\Games\RecordGameInteractionAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Traffic\OpenTrafficVisitAction;
use App\Actions\Traffic\RecordAccountBonusAction;
use App\Actions\Traffic\RecordAccountLoginAction;
use App\Actions\Traffic\RecordAccountSignupAction;
use App\Actions\Traffic\RecordPaidClickAction;
use App\Actions\Traffic\SettleTrafficVisitAction;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GameSignupMode;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\SocialAccount;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameVisitor;
use App\Support\Traffic\Capture\ArrivalToken;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\GamesDemoSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * Plays one visit of the demo's history through the actions the game page
 * and the sign-up forms call, at the moment it happened — the clock set
 * back to it — so every row is written as the site writes it: the click,
 * the page run and the version given, the attempt and its picks, the steps
 * after the game, the email, the share, the way on, the sign-up or the
 * sign-in, the confirmation that may come days later, the visit settling.
 *
 * What a visitor does is drawn with mt_rand from how likely each step is
 * for where they came from: robots race through and sign up with addresses
 * nobody confirms, members of the site play again and share more.
 */
final class DemoPlaythrough
{
    private const array FIRST_NAMES = ['maya', 'leo', 'nora', 'sam', 'ivy', 'omar', 'lena', 'tom', 'aria', 'ben', 'zoe', 'max', 'ella', 'kai', 'mia', 'raj', 'ana', 'finn', 'luz', 'dan'];

    private const array LAST_NAMES = ['brooks', 'silva', 'novak', 'khan', 'meyer', 'rossi', 'tanaka', 'lopez', 'walsh', 'dubois', 'kowal', 'park'];

    /** @var array<string, int> what was played, for the run's summary */
    public array $counts = ['clicks' => 0, 'views' => 0, 'attempts' => 0, 'completed' => 0, 'emails' => 0, 'shares' => 0, 'replays' => 0, 'exits' => 0, 'signups' => 0, 'confirmed' => 0, 'logins' => 0, 'returns' => 0];

    /** @var list<int> the members of the site an anonymous visitor may turn out to be */
    private array $members;

    private int $people = 0;

    /** @var list<array{0: CarbonImmutable, 1: Closure(): void}> what happens after the visit itself: a confirmation, a return, the visit settling */
    private array $later = [];

    /** @param  list<int>  $members */
    public function __construct(
        private readonly Game $game,
        private readonly CarbonImmutable $until,
        private readonly string $password,
        array $members,
    ) {
        $this->members = $members;
    }

    /** The clock of every action, set to a moment of the history. */
    public static function clock(?CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /**
     * One visit: a paid click (`paid`, with its source and link), an unpaid
     * arrival (`unpaid`, with its channel and origin), a click
     * TrafficDemoSeeder recorded (`recorded`, by its visit), or a member of
     * the site signed in (`member`), or the browser back days later on its
     * own (`return`), when it may sign up at last — late, if the click that
     * brought it no longer holds it. Returns the visit the browser comes
     * back for, if it does.
     *
     * @param  array<string, mixed>  $arrival
     * @return array{at: CarbonImmutable, arrival: array<string, mixed>}|null
     */
    public function visit(DemoVisitor $visitor, array $arrival, CarbonImmutable $at): ?array
    {
        self::clock($at);
        // Each visit starts with the rate limits empty: their windows never saw a clock that jumps from visit to visit.
        Cache::store('array')->flush();
        $this->later = [];
        $first = $visitor->visits === 0;
        $visitor->visits++;
        $member = $arrival['kind'] === 'member';
        $userId = $member ? $visitor->memberId : null;
        $signals = DemoTrafficMix::signals($visitor->country, $visitor->device);
        $visitId = null;
        $paid = false;

        if ($arrival['kind'] === 'paid') {
            /** @var TrafficSource $source */
            $source = $arrival['source'];
            $visit = app(RecordPaidClickAction::class)->handle($source, $arrival['query'], $visitor->browserHash, $first, null, 'games.play', GamesDemoSeeder::SLUG, $signals, $at);

            if ($visit === null) {
                return null;
            }

            $this->counts['clicks']++;
            $visitId = (int) $visit->id;
            $paid = true;
        } elseif ($arrival['kind'] === 'recorded') {
            $visitId = (int) $arrival['visit_id'];
            $paid = (bool) $arrival['paid'];
            $this->counts['clicks'] += $paid ? 1 : 0;
        }

        // A click whose page no browser ever ran: a robot of the network, or a tab closed at once.
        if ($paid && ! self::roll((int) $arrival['opens'])) {
            return null;
        }

        $at = $at->addSeconds(mt_rand(2, 12));
        self::clock($at);
        $player = new GameVisitor($member ? GameAudience::Authenticated : GameAudience::Anonymous, $userId, $member ? null : $visitor->browserHash, $visitor->browserHash);
        $assignment = app(AssignGameVersionAction::class)->handle($this->game, $player, viewed: true);

        if ($assignment === null) {
            return null;
        }

        $this->counts['views']++;
        $token = ArrivalToken::make($paid ? ['visit' => $visitId] : [
            'channel' => $arrival['channel'],
            'origin' => $arrival['origin'] ?? null,
            'slug' => GamesDemoSeeder::SLUG,
            'route' => 'games.play',
            'path' => '/games/'.GamesDemoSeeder::SLUG,
            'host' => self::referrerHost($arrival),
        ], $visitor->browserHash, $at->getTimestamp());
        $visitId = app(OpenTrafficVisitAction::class)->handle($token, $visitor->browserHash, $userId, (int) $this->game->id, (int) $assignment->version_id, $signals, $at) ?? $visitId;
        $this->counts['returns'] += $arrival['kind'] === 'return' ? 1 : 0;

        if (self::roll($this->startChance($visitor, $arrival))) {
            $attempt = $this->play($visitor, $player, $visitId, $at);

            if ($attempt->status === GameAttemptStatus::Completed) {
                $this->afterGame($visitor, $player, $attempt, $visitId, $at);
            }
        } elseif (! $member && self::roll(4)) {
            // Left from the first screen for the feed.
            app(RecordGameInteractionAction::class)->handle(GameEventType::Exit, $this->game, $player, null, (int) $assignment->version_id, 'feed');
        }

        // Back on their own days later, some sign up at last, having played again or not.
        if ($arrival['kind'] === 'return' && $visitor->userId === null && self::roll(38)) {
            $this->signUp($visitor, $at->addMinutes(mt_rand(3, 25)), self::roll(50) ? AccountSignupPlace::Game : (self::roll(60) ? AccountSignupPlace::Feed : AccountSignupPlace::Other));
        }

        if ($paid && $visitId !== null) {
            $this->settleLater($visitId);
        }

        $this->runLater();

        // One more game, or a late sign-up: some come back days later.
        if (! $member && ! $visitor->bot && $visitor->userId === null && $visitor->visits < 3 && self::roll($arrival['kind'] === 'return' ? 15 : 7)) {
            $back = $at->addHours(mt_rand(30, 150));

            return $back->greaterThan($this->until) ? null : ['at' => $back, 'arrival' => ['kind' => 'return', ...self::returnChannel()]];
        }

        return null;
    }

    /** Whether the visitor starts at all, by where they came from. */
    private function startChance(DemoVisitor $visitor, array $arrival): int
    {
        return match (true) {
            $visitor->bot => 93,
            $arrival['kind'] === 'member' => 70,
            $arrival['kind'] === 'return' => 55,
            in_array($arrival['channel'] ?? null, ['shared', 'internal'], true) => 74,
            ($arrival['channel'] ?? null) === 'search' => 66,
            default => 58,
        };
    }

    /**
     * An attempt, from Start to the last pick or the round the visitor gave
     * up on; picks are the crowd's as often as the visitor's taste has it.
     */
    private function play(DemoVisitor $visitor, GameVisitor $player, ?int $visitId, CarbonImmutable &$at, ?GameAttempt $from = null): GameAttempt
    {
        $at = $at->addSeconds($visitor->bot ? mt_rand(1, 3) : mt_rand(4, 40));
        self::clock($at);
        $attempt = app(StartGameAttemptAction::class)->handle($this->game, $player, 'en', $from !== null, $visitId, $from);
        $this->counts['attempts']++;

        if ($player->isAnonymous()) {
            $visitor->guestAttempts[] = $attempt->public_id;
        }

        $snapshot = $attempt->version->read();
        $quits = $visitor->bot ? 1 : ($player->isAnonymous() ? 7 : 5);

        for ($round = 0; $round < $snapshot->roundCount(); $round++) {
            if ($round > 0 && self::roll($quits)) {
                return $attempt;
            }

            $at = $at->addSeconds($visitor->bot ? mt_rand(1, 2) : mt_rand(3, 15));
            self::clock($at);
            $answer = $snapshot->round($round)?->answer;
            $position = $answer === null ? mt_rand(0, 1) : (mt_rand(1, 1000) <= (int) ($visitor->taste * 1000) ? $answer : 1 - $answer);
            app(RecordGameAnswerAction::class)->handle($attempt, $player, $round, $position);
        }

        $this->counts['completed']++;

        return $attempt;
    }

    /**
     * The steps after the game, as the visitor goes through them: the
     * preview, the email asked for, the full result — shared, played again,
     * signed up from — and the way on to a post or the feed.
     */
    private function afterGame(DemoVisitor $visitor, GameVisitor $player, GameAttempt $attempt, ?int $visitId, CarbonImmutable &$at): void
    {
        $steps = $attempt->version->read()->flowSteps();
        $last = $steps === [] ? null : $steps[array_key_last($steps)]->key;
        $anonymous = $player->isAnonymous();

        for ($moves = 0; $attempt->current_step_key !== null && $moves < 8; $moves++) {
            $step = GameFlowRunner::find($steps, (string) $attempt->current_step_key);

            if ($step === null) {
                return;
            }

            $at = $at->addSeconds(mt_rand(4, 30));
            self::clock($at);

            switch ($step->type) {
                case GameFlowStepType::EmailCapture:
                    if ($anonymous && $visitor->userId === null && self::roll($visitor->bot ? 60 : 31)) {
                        app(CaptureGameEmailAction::class)->handle($attempt, $player, $this->email($visitor), self::roll(45));
                        $this->counts['emails']++;
                    }
                    break;

                case GameFlowStepType::FullResult:
                    if ((bool) $step->setting('share_enabled')) {
                        $this->share($visitor, $player, $attempt, $anonymous ? 10 : 22);
                    }

                    if ($anonymous && $step->asksForAccount() && $step->signup() === GameSignupMode::Inline && $this->signUpBegun($visitor, $player, $attempt, $at, 'email')) {
                        return;
                    }

                    if (! $visitor->bot && self::roll($anonymous ? 14 : 30)) {
                        $this->counts['replays']++;
                        $replay = $this->play($visitor, $player, $visitId, $at, $attempt);

                        if ($replay->status === GameAttemptStatus::Completed && self::roll(50)) {
                            $this->afterGame($visitor, $player, $replay, $visitId, $at);
                        }

                        return;
                    }

                    if ($step->onward() !== GameOnward::Next) {
                        $this->leave($visitor, $player, $attempt, $step, $at);

                        return;
                    }
                    break;

                case GameFlowStepType::Registration:
                    if ($anonymous && $this->signUpBegun($visitor, $player, $attempt, $at, 'register')) {
                        return;
                    }
                    break;

                case GameFlowStepType::Share:
                    $this->share($visitor, $player, $attempt, 28);
                    break;

                case GameFlowStepType::Feed:
                case GameFlowStepType::GoToPost:
                case GameFlowStepType::GoToPage:
                    $this->leave($visitor, $player, $attempt, $step, $at);

                    return;

                default:
                    break;
            }

            // Nothing after the last step but leaving; elsewhere most go on.
            if ($step->key === $last || ! self::roll($step->type === GameFlowStepType::ResultPreview ? 93 : 72)) {
                // Gone without a word: the tab closed, or the feed asked for.
                if (self::roll(30)) {
                    app(RecordGameInteractionAction::class)->handle(GameEventType::Exit, $this->game, $player, $attempt, (int) $attempt->version_id, 'feed');
                    $this->counts['exits']++;
                }

                return;
            }

            app(MoveGameAttemptAction::class)->handle($attempt, $player, (string) $attempt->current_step_key);
        }
    }

    private function share(DemoVisitor $visitor, GameVisitor $player, GameAttempt $attempt, int $chance): void
    {
        if (! $visitor->bot && self::roll($chance)) {
            app(RecordGameInteractionAction::class)->handle(GameEventType::ShareClicked, $this->game, $player, $attempt, (int) $attempt->version_id, self::roll(60) ? 'copy_link' : 'native');
            $this->counts['shares']++;
        }
    }

    /**
     * Out of the game the way the step leads: to the post it picks or to the
     * feed. Now and then the visitor signs up there a few minutes later.
     */
    private function leave(DemoVisitor $visitor, GameVisitor $player, GameAttempt $attempt, FlowStepDraft $step, CarbonImmutable &$at): void
    {
        if (! self::roll($player->isAnonymous() ? 56 : 66)) {
            return;
        }

        app(LeaveGameAction::class)->handle($this->game, $player, $attempt);
        $this->counts['exits']++;

        if ($player->isAnonymous() && ! $visitor->bot && $visitor->userId === null && self::roll(7)) {
            $at = $at->addMinutes(mt_rand(2, 9));
            $this->signUp($visitor, $at, $step->sendsToPost() ? AccountSignupPlace::Post : AccountSignupPlace::Feed);
        }
    }

    /**
     * The visitor begins signing up from the game — on the result with an
     * email or a provider, or the sign-up or log-in dialog of a Registration
     * step — and may go through with it: a new account, or a member signing
     * in to the one they have. Whether the visitor went through with it.
     */
    private function signUpBegun(DemoVisitor $visitor, GameVisitor $player, GameAttempt $attempt, CarbonImmutable &$at, string $way): bool
    {
        if ($visitor->userId !== null || ! self::roll($visitor->bot ? 85 : 15)) {
            return false;
        }

        $method = $visitor->bot ? AccountSignupMethod::Email : [AccountSignupMethod::Email, AccountSignupMethod::Email, AccountSignupMethod::Email, AccountSignupMethod::Google, AccountSignupMethod::Google, AccountSignupMethod::Facebook][mt_rand(0, 5)];
        $login = ! $visitor->bot && self::roll(12);
        $detail = $way === 'register' ? ($login ? 'login' : 'register') : ($login ? 'login' : $method->value);
        app(RecordGameInteractionAction::class)->handle(GameEventType::RegistrationCtaClicked, $this->game, $player, $attempt, (int) $attempt->version_id, $detail);

        if (! self::roll($visitor->bot ? 90 : ($method === AccountSignupMethod::Email ? 55 : 72))) {
            return false;
        }

        $at = $at->addSeconds(mt_rand(20, 150));

        if ($login) {
            $this->signIn($visitor, $at);
        } else {
            $this->signUp($visitor, $at, AccountSignupPlace::Game, $method);
        }

        return true;
    }

    /**
     * A new account from this browser, as registering makes one: the
     * browser's guest attempts become its own, then what the account is
     * credited to is recorded. An email address is confirmed minutes or days
     * later, or never; Google and Facebook confirm at once.
     */
    public function signUp(DemoVisitor $visitor, CarbonImmutable $at, AccountSignupPlace $place, ?AccountSignupMethod $method = null): void
    {
        self::clock($at);
        $method ??= $visitor->bot ? AccountSignupMethod::Email : (self::roll(62) ? AccountSignupMethod::Email : (self::roll(75) ? AccountSignupMethod::Google : AccountSignupMethod::Facebook));
        $social = $method !== AccountSignupMethod::Email;
        $email = $this->email($visitor);
        $this->people++;

        $user = new User;
        $user->forceFill([
            'name' => $visitor->bot ? 'user'.substr($visitor->browserHash, 0, 5) : ucwords(str_replace('.', ' ', implode('.', array_slice(explode('.', explode('@', $email)[0]), 0, 2)))),
            'username' => 'player_'.str_pad((string) $this->people, 4, '0', STR_PAD_LEFT),
            'email' => $email,
            'password' => $this->password,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'trust_level' => 0,
            'email_verified_at' => $social ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        if ($social) {
            SocialAccount::query()->create(['user_id' => $user->id, 'provider' => $method->value, 'provider_user_id' => (string) (7_000_000 + $this->people), 'provider_email' => $email, 'provider_email_verified' => true]);
        }

        app(ClaimGuestGameAttemptsAction::class)->handle($user, $visitor->guestAttempts, GameAccountOutcome::Registered);
        app(RecordAccountSignupAction::class)->handle($user, $visitor->browserHash, $method, $place, $at);
        $visitor->userId = (int) $user->id;
        $visitor->signedUp = true;
        $visitor->guestAttempts = [];
        $this->counts['signups']++;
        $confirmedAt = $social ? $at : $this->confirmation($visitor, $at);

        if ($confirmedAt !== null && ! $social) {
            $this->later[] = [$confirmedAt, function () use ($user, $confirmedAt): void {
                $user->forceFill(['email_verified_at' => $confirmedAt])->save();
                app(ConfirmAccountAttributionAction::class)->handle((int) $user->id, $confirmedAt);
            }];
        }

        if ($confirmedAt !== null) {
            $this->counts['confirmed']++;

            // Back on another day, which the account is worth a little more for.
            if (self::roll(35)) {
                $back = $confirmedAt->addDays(mt_rand(1, 4))->startOfDay()->addHours(mt_rand(8, 22))->addMinutes(mt_rand(0, 59));
                $this->later[] = [$back, fn () => app(RecordAccountBonusAction::class)->returned((int) $user->id, $back)];
            }
        }
    }

    /** A member of the site signs in on this browser, the attempts they played as a guest becoming theirs. */
    private function signIn(DemoVisitor $visitor, CarbonImmutable $at): void
    {
        self::clock($at);
        $user = User::query()->findOrFail($this->members[mt_rand(0, count($this->members) - 1)]);
        app(ClaimGuestGameAttemptsAction::class)->handle($user, $visitor->guestAttempts, GameAccountOutcome::SignedIn);
        app(RecordAccountLoginAction::class)->handle($user, $visitor->browserHash, $at);
        $visitor->userId = (int) $user->id;
        $visitor->guestAttempts = [];
        $this->counts['logins']++;
    }

    /** When an email address is confirmed, if it ever is: most within minutes, some hours or days later; robots almost never. */
    private function confirmation(DemoVisitor $visitor, CarbonImmutable $at): ?CarbonImmutable
    {
        if (! self::roll($visitor->bot ? 3 : 72)) {
            return null;
        }

        $roll = mt_rand(1, 100);
        $confirmed = match (true) {
            $roll <= 55 => $at->addSeconds(mt_rand(40, 900)),
            $roll <= 85 => $at->addMinutes(mt_rand(20, 480)),
            default => $at->addHours(mt_rand(24, 72)),
        };

        return $confirmed->greaterThan($this->until) ? null : $confirmed;
    }

    /** A paid visit comes to rest half an hour after its last step, or a day after the click, as the settling run finds it. */
    private function settleLater(int $visitId): void
    {
        $visit = TrafficVisit::query()->find($visitId, ['id', 'arrived_at', 'last_seen_at', 'opened_at', 'settled_at']);

        if ($visit === null || $visit->opened_at === null || $visit->settled_at !== null) {
            return;
        }

        $at = CarbonImmutable::instance($visit->last_seen_at)->addMinutes(30)->min(CarbonImmutable::instance($visit->arrived_at)->addHours(24));

        // Settled already by then, when an account came first.
        $this->later[] = [$at, fn () => app(SettleTrafficVisitAction::class)->handle($visitId, $at)];
    }

    /** What happens after the visit, in the order it happens, each at its own moment. */
    private function runLater(): void
    {
        usort($this->later, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($this->later as [$at, $then]) {
            if ($at->greaterThan($this->until)) {
                continue;
            }

            self::clock($at);
            $then();
        }

        $this->later = [];
    }

    /** An address for a visitor: a person's name, or a robot's string of letters. */
    private function email(DemoVisitor $visitor): string
    {
        $tag = substr($visitor->browserHash, 0, 8);

        return $visitor->bot
            ? substr(md5($visitor->browserHash), 0, 9).'@example.net'
            : self::FIRST_NAMES[hexdec(substr($tag, 0, 2)) % count(self::FIRST_NAMES)].'.'.self::LAST_NAMES[hexdec(substr($tag, 2, 2)) % count(self::LAST_NAMES)].'.'.$tag.'@example.com';
    }

    /** @return array{channel: string, origin: string|null} */
    private static function returnChannel(): array
    {
        return [['channel' => 'direct', 'origin' => null], ['channel' => 'search', 'origin' => 'google'], ['channel' => 'shared', 'origin' => 'result page'], ['channel' => 'internal', 'origin' => 'feed']][mt_rand(0, 3)];
    }

    /**
     * The host an unpaid arrival's referrer would have had: another site's
     * own, a search engine's main one, none for the rest.
     *
     * @param  array<string, mixed>  $arrival
     */
    private static function referrerHost(array $arrival): ?string
    {
        $origin = is_string($arrival['origin'] ?? null) ? $arrival['origin'] : null;

        return match (true) {
            $origin === null => null,
            str_contains($origin, '.') => $origin,
            ($arrival['channel'] ?? null) === 'search' => $origin.'.com',
            default => null,
        };
    }

    private static function roll(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }
}
