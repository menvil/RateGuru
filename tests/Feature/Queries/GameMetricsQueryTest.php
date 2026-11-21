<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameOnwardKind;
use App\Enums\TrafficDevice;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\PeriodVersionFunnel;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A game's figures, read from what playing it recorded: visitors from `view`
 * events, each person once — a guest by the cookie's hash, a player by the
 * account — and everything else from the attempts started in the window,
 * however late they finished. Split by version, flow or content set, a person
 * who saw two versions is one visitor in all and one in each. Narrowed to a
 * device or country, or split by them, only what came on a recorded visit
 * counts: an attempt by its own visit, a visitor by their visits of the game.
 * The same on every database engine.
 */
/** @return GameMetricsQuery read both ways, each call compared (readBothWays()) */
function metricsQuery(): object
{
    return readBothWays(GameMetricsQuery::class);
}

function metricsScope(GameVersion $version, GameAudience $audience = GameAudience::Anonymous, ?int $versionId = null, ?VisitFilter $visits = null): GameMetricsScope
{
    return new GameMetricsScope((int) $version->game_id, $audience, CarbonImmutable::parse('2026-11-01 00:00:00'), CarbonImmutable::parse('2026-11-09 00:00:00'), $versionId, $visits);
}

/** A recorded visit of the version's game by a guest's browser (a hash), from a device and a country, or none known. */
function metricsVisit(GameVersion $version, string $browser, TrafficDevice $device = TrafficDevice::Mobile, ?string $country = null, array $attributes = []): TrafficVisit
{
    return TrafficVisit::factory()->unpaid()->create([
        'game_id' => $version->game_id,
        'version_id' => $version->id,
        'visitor_hash' => hash('sha256', $browser),
        'device' => $device,
        'country' => $country,
        'arrived_at' => '2026-11-05 11:59:00',
        'network_day' => '2026-11-05',
        ...$attributes,
    ]);
}

/** A `view` of the version by a guest (a hash) or a player (a user), or another event with its detail. */
function metricsView(GameVersion $version, string|User $who, string $at = '2026-11-05 12:00:00', GameEventType $type = GameEventType::View, ?GameAttempt $attempt = null, ?string $provider = null, ?GameOnwardKind $stepType = null): void
{
    (new GameEvent)->forceFill([
        'game_id' => $version->game_id,
        'version_id' => $version->id,
        'attempt_id' => $attempt?->id,
        'audience' => $who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous,
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => $who instanceof User ? null : hash('sha256', $who),
        'type' => $type,
        'step_type' => $stepType?->value,
        'provider' => $provider,
        'occurred_at' => $at,
    ])->save();
}

/**
 * An attempt started at a moment and finished five minutes on, which the
 * flow's way on sent somewhere a minute later, as LeaveGameAction records it:
 * where it went on the attempt, its exit there, and for a post the post opened.
 */
function metricsWentOn(GameVersion $version, string|User $who, GameOnwardKind $way, string $at = '2026-11-05 12:00:00'): GameAttempt
{
    $start = CarbonImmutable::parse($at);
    $onward = $start->addMinutes(6)->toDateTimeString();
    $attempt = metricsAttempt($version, $who, $at, [...metricsCompleted(), 'completed_at' => $start->addMinutes(5)->toDateTimeString(), 'onward_kind' => $way, 'onward_at' => $onward]);
    metricsView($version, $who, $onward, GameEventType::Exit, $attempt, stepType: $way);

    if ($way === GameOnwardKind::Post) {
        metricsView($version, $who, $onward, GameEventType::PostOpened, $attempt);
    }

    return $attempt;
}

/** An attempt at the version by a guest (a hash) or a player (a user), started at a moment. */
function metricsAttempt(GameVersion $version, string|User $who, string $at = '2026-11-05 12:00:00', array $attributes = []): GameAttempt
{
    return GameAttempt::factory()->create([
        'version_id' => $version->id,
        'audience' => $who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous,
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => $who instanceof User ? null : hash('sha256', $who),
        'started_at' => $at,
        'last_activity_at' => $at,
        ...$attributes,
    ]);
}

function metricsCompleted(string $profile = 'oracle'): array
{
    return ['status' => GameAttemptStatus::Completed, 'completed_at' => '2026-11-05 12:05:00', 'matches' => 1, 'score' => 1, 'profile_key' => $profile];
}

/** A second version of the game, from a flow of its own. */
function metricsSecondVersion(array $draft): GameVersion
{
    $flow = GameFlow::factory()->create(['game_id' => $draft['game']->id]);

    foreach ($draft['flow']->steps()->get() as $step) {
        GameFlowStep::factory()->type($step->type)->create(['flow_id' => $flow->id, 'position' => $step->position]);
    }

    return app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game']->fresh(), $draft['set']->id, $flow->id);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('counts each visitor once, in the window and the audience asked for', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $player = User::factory()->create();

    metricsView($version, 'guest-a');
    metricsView($version, 'guest-a', '2026-11-06 09:00:00');
    metricsView($version, 'guest-b');
    // Outside the window, before and at its end.
    metricsView($version, 'guest-c', '2026-10-31 23:59:59');
    metricsView($version, 'guest-d', '2026-11-09 00:00:00');
    // Not a view.
    metricsView($version, 'guest-e', type: GameEventType::StepViewed);
    metricsView($version, $player);
    metricsView($version, $player);
    // Another game's visitor.
    metricsView(liveGame(publishableGameDraft(rounds: 1)), 'guest-f');

    expect(metricsQuery()->funnels(metricsScope($version))[0]->visitors)->toBe(2)
        ->and(metricsQuery()->funnels(metricsScope($version, GameAudience::Authenticated))[0]->visitors)->toBe(1);
});

it('counts the attempts started in the window, however late they finished, what they led to, and the people behind them', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $newcomer = User::factory()->create();
    $returning = User::factory()->create();

    $completed = metricsAttempt($version, 'guest-a', attributes: metricsCompleted());
    // Started in the window and finished after it: completed all the same.
    metricsAttempt($version, 'guest-a', '2026-11-08 23:59:00', [...metricsCompleted(), 'completed_at' => '2026-11-09 00:03:00']);
    $registered = metricsAttempt($version, 'guest-b', attributes: [...metricsCompleted(), 'user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    // The same new account from a second attempt is one account.
    metricsAttempt($version, 'guest-b', attributes: ['user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    // A new account counts once it is confirmed, on the play it was credited to.
    AccountAttribution::factory()->confirmed()->create(['user_id' => $newcomer->id, 'credited_attempt_id' => $registered->id]);
    // One never confirmed counts for nothing.
    AccountAttribution::factory()->create(['credited_attempt_id' => $completed->id]);
    $signedIn = metricsAttempt($version, 'guest-c', attributes: ['user_id' => $returning->id, 'account_outcome' => GameAccountOutcome::SignedIn]);
    // Started before the window.
    metricsAttempt($version, 'guest-d', '2026-10-30 12:00:00', metricsCompleted());

    GameEmailCapture::query()->forceCreate(['attempt_id' => $completed->id, 'game_id' => $version->game_id, 'email' => 'a@example.test', 'captured_at' => now()]);
    metricsView($version, 'guest-a', type: GameEventType::ShareClicked, attempt: $completed);
    metricsView($version, 'guest-a', type: GameEventType::ShareClicked, attempt: $completed);
    metricsView($version, 'guest-b', type: GameEventType::ShareClicked, attempt: $registered);
    // Signing up begun: by email, then again; logging in, and Google too.
    metricsView($version, 'guest-b', type: GameEventType::RegistrationCtaClicked, attempt: $registered, provider: 'email');
    metricsView($version, 'guest-b', type: GameEventType::RegistrationCtaClicked, attempt: $registered, provider: 'email');
    metricsView($version, 'guest-c', type: GameEventType::RegistrationCtaClicked, attempt: $signedIn, provider: 'login');
    metricsView($version, 'guest-c', type: GameEventType::RegistrationCtaClicked, attempt: $signedIn, provider: 'google');

    expect(metricsQuery()->funnels(metricsScope($version))[0])->toEqual(new GameFunnel(
        visitors: 0,
        started: 5,
        completed: 3,
        emails: 1,
        registered: 1,
        signedIn: 1,
        shared: 2,
        replayers: 2,
        starters: 3,
        finishers: 2,
        emailers: 1,
        sharers: 2,
        signUpStarters: 2,
    ))
        ->and(metricsQuery()->signUpMethods(metricsScope($version)))->toBe(['email' => 1, 'google' => 1, 'login' => 1]);
});

it('counts a share made through the site’s own share address, a person once however often they share', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $slug = $version->game->slug;
    $first = metricsAttempt($version, 'guest-a', attributes: ['game_id' => $version->game_id, ...metricsCompleted()]);
    // The same guest again, from a second attempt.
    $second = metricsAttempt($version, 'guest-a', attributes: ['game_id' => $version->game_id, ...metricsCompleted()]);

    $share = fn (GameAttempt $attempt, string $provider) => $this->get(route('games.player-share', ['slug' => $slug, 'attempt' => $attempt->public_id, 'provider' => $provider]))->assertRedirect();
    $sharing = function () use ($version): array {
        $funnel = metricsQuery()->funnels(metricsScope($version))[0];

        return [$funnel->shared, $funnel->sharers];
    };

    $share($first, 'x');

    expect($sharing())->toBe([1, 1]);

    $share($first, 'telegram');
    $share($second, 'x');

    expect(GameEvent::query()->where('type', GameEventType::ShareClicked)->count())->toBe(3)
        ->and($sharing())->toBe([2, 1]);
});

it('counts the people the flow’s way on sent to a post, the feed, a page or the next game, and the votes credited to those plays, each person once', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $player = User::factory()->create();
    // Within the 30 minutes after a play went on at 12:06.
    $credited = '2026-11-05 12:10:00';
    $wentOn = fn (GameOnwardKind $way, string $guest): GameAttempt => metricsWentOn($version, $guest, $way);

    // Every count differs from the others, so a condition read for the wrong one changes the figures.
    // Seven guests went on to the post, post-1 twice. Six voted there and five rated it, each as the account they
    // signed up as, on their guest play: post-6 voted without rating, post-7 did neither.
    $toPost = array_map(fn (int $n): GameAttempt => $wentOn(GameOnwardKind::Post, "post-{$n}"), range(1, 7));
    $wentOn(GameOnwardKind::Post, 'post-1');

    foreach (User::factory()->count(6)->create() as $index => $voter) {
        metricsView($version, $voter, $credited, GameEventType::PostVoted, $toPost[$index]);

        if ($index < 5) {
            metricsView($version, $voter, $credited, GameEventType::PostRated, $toPost[$index]);
        }
    }

    // Four went on to the feed; feed-1 left for it again, and alone voted three times there.
    $toFeed = array_map(fn (int $n): GameAttempt => $wentOn(GameOnwardKind::Feed, "feed-{$n}"), range(1, 4));
    metricsView($version, 'feed-1', $credited, GameEventType::Exit, $toFeed[0], stepType: GameOnwardKind::Feed);
    metricsView($version, 'feed-1', $credited, GameEventType::FeedVoted, $toFeed[0]);
    // Another took the link to the feed at the top of the screen: an exit to the feed, but no way on of the flow's.
    $skipped = metricsAttempt($version, 'header-link', attributes: metricsCompleted());
    metricsView($version, 'header-link', '2026-11-05 12:06:00', GameEventType::Exit, $skipped, stepType: GameOnwardKind::Feed);

    // Two went on to a page, and three to the next game.
    foreach (['page-1', 'page-2'] as $guest) {
        $wentOn(GameOnwardKind::Page, $guest);
    }

    foreach (['next-1', 'next-2', 'next-3'] as $guest) {
        $wentOn(GameOnwardKind::NextGame, $guest);
    }

    // Started before the window, and a logged-in player's: neither is an anonymous visitor's of the window.
    metricsWentOn($version, 'early', GameOnwardKind::Post, '2026-10-30 12:00:00');
    metricsWentOn($version, $player, GameOnwardKind::Post);

    $ways = fn (GameFunnel $f): array => [$f->wentToPost, $f->votedOnPost, $f->ratedPost, $f->wentToFeed, $f->votedInFeed, $f->wentToPage, $f->wentToNextGame];
    $anonymous = metricsQuery()->funnels(metricsScope($version))[0];

    expect($ways($anonymous))->toBe([7, 6, 5, 4, 1, 2, 3])
        // The other counts read from the same events stay as they were.
        ->and([$anonymous->shared, $anonymous->sharers, $anonymous->signUpStarters, $anonymous->finishers])->toBe([0, 0, 0, 17])
        ->and($ways(metricsQuery()->funnels(metricsScope($version, GameAudience::Authenticated))[0]))->toBe([1, 0, 0, 0, 0, 0, 0]);
});

it('splits the ways on by version and by the device of the play’s visit, a person once in each', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $phone = metricsVisit($first, 'guest-a', TrafficDevice::Mobile, 'DE');

    // guest-a went on to the post from both versions, the first time on a phone; guest-b to the feed from the second.
    $onPhone = metricsWentOn($first, 'guest-a', GameOnwardKind::Post);
    $onPhone->forceFill(['traffic_visit_id' => $phone->id])->save();
    metricsView($first, 'guest-a', type: GameEventType::PostVoted, attempt: $onPhone);
    metricsWentOn($second, 'guest-a', GameOnwardKind::Post);
    metricsWentOn($second, 'guest-b', GameOnwardKind::Feed);

    $ways = fn (GameFunnel $f): array => [$f->wentToPost, $f->votedOnPost, $f->wentToFeed];
    $byVersion = metricsQuery()->funnels(metricsScope($first), 'version');

    expect($ways(metricsQuery()->funnels(metricsScope($first))[0]))->toBe([1, 1, 1])
        ->and($ways($byVersion[$first->id]))->toBe([1, 1, 0])
        ->and($ways($byVersion[$second->id]))->toBe([1, 0, 1])
        ->and($ways(metricsQuery()->funnels(metricsScope($first, versionId: $second->id))[0]))->toBe([1, 0, 1])
        ->and(array_map($ways, metricsQuery()->funnels(metricsScope($first), 'device')))->toBe(['mobile' => [1, 1, 0]])
        ->and($ways(metricsQuery()->funnels(metricsScope($first, visits: new VisitFilter(TrafficDevice::Desktop)))[0]))->toBe([0, 0, 0]);
});

it('follows the people sent to a post to its sign-in form, an account made there and confirmed, within 30 minutes, each person once, split like the rest', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $player = User::factory()->create();
    // Every play below goes on to the post at 12:06.
    $form = fn (GameAttempt $attempt, string $guest, string $at = '2026-11-05 12:10:00') => metricsView($first, $guest, $at, GameEventType::PostSignInForm, $attempt);
    $markedVisit = function (GameAttempt $attempt, string $guest, ?string $formAt, TrafficDevice $device = TrafficDevice::Mobile) use ($first): void {
        $attempt->forceFill(['traffic_visit_id' => metricsVisit($first, $guest, $device, attributes: ['signup_form_at' => $formAt])->id])->save();
    };
    $account = fn (GameAttempt $attempt, string $at, AccountSignupPlace $place = AccountSignupPlace::Post, bool $confirmed = false) => AccountAttribution::factory()->create([
        'credited_attempt_id' => $attempt->id,
        'signup_place' => $place,
        'signed_up_at' => $at,
        'confirmed_at' => $confirmed ? '2026-11-05 13:00:00' : null,
    ]);

    // V1. guest-a saw the form on both of their plays: one person. guest-b's recorded visit was marked 14 minutes
    // after going on, as visits were before the event; guest-c's 31 minutes after, and guest-d's before going on:
    // neither saw it there. guest-h signed up on the post 34 minutes after: too late for anything.
    $form(metricsWentOn($first, 'guest-a', GameOnwardKind::Post), 'guest-a');
    $form(metricsWentOn($first, 'guest-a', GameOnwardKind::Post), 'guest-a');
    $markedVisit(metricsWentOn($first, 'guest-b', GameOnwardKind::Post), 'guest-b', '2026-11-05 12:20:00');
    $markedVisit(metricsWentOn($first, 'guest-c', GameOnwardKind::Post), 'guest-c', '2026-11-05 12:37:00');
    $markedVisit(metricsWentOn($first, 'guest-d', GameOnwardKind::Post), 'guest-d', '2026-11-05 12:05:00', TrafficDevice::Desktop);
    $account(metricsWentOn($first, 'guest-h', GameOnwardKind::Post), '2026-11-05 12:40:00', confirmed: true);

    // V2. guest-e signed up on the post and confirmed — no form event, but signing up there took the form; guest-f saw it
    // and signed up on the post 24 minutes after going on, not confirmed yet; guest-g saw it and signed up in the game.
    $account(metricsWentOn($second, 'guest-e', GameOnwardKind::Post), '2026-11-05 12:15:00', confirmed: true);
    $toPost = metricsWentOn($second, 'guest-f', GameOnwardKind::Post);
    $form($toPost, 'guest-f');
    $account($toPost, '2026-11-05 12:30:00');
    $toPost = metricsWentOn($second, 'guest-g', GameOnwardKind::Post);
    $form($toPost, 'guest-g');
    $account($toPost, '2026-11-05 12:10:00', AccountSignupPlace::Game, confirmed: true);

    // Sent to the feed, a play before the window, and a logged-in player's: none of them anonymous visitors of a post in the window.
    // The game's first form event is at 12:10, after every play here went on, so each visit's mark still stands in for it.
    $toFeed = metricsWentOn($first, 'guest-i', GameOnwardKind::Feed);
    $markedVisit($toFeed, 'guest-i', '2026-11-05 12:10:00');
    $account($toFeed, '2026-11-05 12:10:00', confirmed: true);
    $markedVisit(metricsWentOn($first, 'early', GameOnwardKind::Post, '2026-10-30 12:00:00'), 'early', '2026-10-30 12:10:00');
    metricsView($first, $player, '2026-11-05 12:10:00', GameEventType::PostSignInForm, metricsWentOn($first, $player, GameOnwardKind::Post));

    $onPost = fn (GameFunnel $f): array => [$f->wentToPost, $f->sawSignInOnPost, $f->signedUpOnPost, $f->confirmedOnPost];
    $byVersion = metricsQuery()->funnels(metricsScope($first), 'version');

    expect($onPost(metricsQuery()->funnels(metricsScope($first))[0]))->toBe([8, 5, 2, 1])
        ->and($onPost($byVersion[$first->id]))->toBe([5, 2, 0, 0])
        ->and($onPost($byVersion[$second->id]))->toBe([3, 3, 2, 1])
        ->and($onPost(metricsQuery()->funnels(metricsScope($first, versionId: $second->id))[0]))->toBe([3, 3, 2, 1])
        // Only the plays that came on a recorded visit, by its device.
        ->and(array_map($onPost, metricsQuery()->funnels(metricsScope($first), 'device')))->toBe(['desktop' => [1, 0, 0, 0], 'mobile' => [2, 1, 0, 0]])
        ->and($onPost(metricsQuery()->funnels(metricsScope($first, GameAudience::Authenticated))[0]))->toBe([1, 1, 0, 0]);
});

it('takes the recorded visit’s mark for the post’s sign-in form only on plays sent on before the game first recorded the form itself', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $marked = function (GameAttempt $attempt, string $guest, string $formAt) use ($version): void {
        $attempt->forceFill(['traffic_visit_id' => metricsVisit($version, $guest, attributes: ['signup_form_at' => $formAt])->id])->save();
    };
    $onPost = fn (): array => [($f = metricsQuery()->funnels(metricsScope($version))[0])->wentToPost, $f->sawSignInOnPost];

    // guest-a went on to the post at 12:06 on the 5th and a sign-up dialog marked the visit at 12:20; guest-b went on at 12:06 on the
    // 6th, then opened the dialog on the feed at 12:15 — the visit's mark can't tell where.
    $marked(metricsWentOn($version, 'guest-a', GameOnwardKind::Post), 'guest-a', '2026-11-05 12:20:00');
    $marked(metricsWentOn($version, 'guest-b', GameOnwardKind::Post, '2026-11-06 12:00:00'), 'guest-b', '2026-11-06 12:15:00');

    // The game has recorded no form yet: every play is from before, and both marks stand in for it.
    expect($onPost())->toBe([2, 2]);

    // guest-c's form on the post that evening is the game's first: from then on only the event counts, so guest-b's
    // mark no longer does, while guest-a's play, sent on before it, still counts by its mark.
    $toPost = metricsWentOn($version, 'guest-c', GameOnwardKind::Post, '2026-11-05 18:00:00');
    metricsView($version, 'guest-c', '2026-11-05 18:08:00', GameEventType::PostSignInForm, $toPost);

    expect($onPost())->toBe([3, 2]);
});

it('splits the figures by version, flow and content set, a person once in each', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);

    metricsView($first, 'guest-a');
    metricsView($second, 'guest-a');
    metricsView($second, 'guest-b');
    metricsAttempt($first, 'guest-a', attributes: metricsCompleted());
    metricsAttempt($second, 'guest-b');

    $byVersion = metricsQuery()->funnels(metricsScope($first), 'version');
    $byFlow = metricsQuery()->funnels(metricsScope($first), 'flow');
    $bySet = metricsQuery()->funnels(metricsScope($first), 'set');

    expect(array_keys($byVersion))->toBe([$first->id, $second->id])
        ->and([$byVersion[$first->id]->visitors, $byVersion[$first->id]->started, $byVersion[$first->id]->completed])->toBe([1, 1, 1])
        ->and([$byVersion[$second->id]->visitors, $byVersion[$second->id]->started, $byVersion[$second->id]->completed])->toBe([2, 1, 0])
        ->and(array_keys($byFlow))->toBe([(int) $first->flow_id, (int) $second->flow_id])
        // One set: guest-a saw both versions, and is one visitor of the set.
        ->and(array_keys($bySet))->toBe([(int) $first->content_set_id])
        ->and([$bySet[$first->content_set_id]->visitors, $bySet[$first->content_set_id]->started])->toBe([2, 2])
        ->and(metricsQuery()->funnels(metricsScope($first))[0]->visitors)->toBe(2)
        // One version alone.
        ->and(metricsQuery()->funnels(metricsScope($first, versionId: $second->id))[0]->started)->toBe(1);
});

it('counts the people who finished one of the versions given, each once however many of them they finished', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $third = metricsSecondVersion($draft);
    $player = User::factory()->create();

    metricsAttempt($first, 'guest-a', attributes: metricsCompleted());
    metricsAttempt($second, 'guest-a', attributes: metricsCompleted());
    metricsAttempt($second, 'guest-b', attributes: metricsCompleted());
    // Started only, of another version, before the window, or a logged-in player's: none of them.
    metricsAttempt($first, 'guest-c');
    metricsAttempt($third, 'guest-d', attributes: metricsCompleted());
    metricsAttempt($first, 'guest-e', '2026-10-30 12:00:00', metricsCompleted());
    metricsAttempt($first, $player, attributes: metricsCompleted());

    expect(metricsQuery()->finishers(metricsScope($first), [$first->id, $second->id]))->toBe(2)
        ->and(metricsQuery()->finishers(metricsScope($first), [$first->id]))->toBe(1)
        ->and(metricsQuery()->finishers(metricsScope($first), []))->toBe(0)
        ->and(metricsQuery()->finishers(metricsScope($first, GameAudience::Authenticated), [$first->id, $second->id]))->toBe(1);
});

it('counts the people one of the versions given sent on to a post, each once however many of them sent them', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $third = metricsSecondVersion($draft);
    $player = User::factory()->create();

    // guest-a was sent to a post by both versions, guest-b by the second, twice.
    metricsWentOn($first, 'guest-a', GameOnwardKind::Post);
    metricsWentOn($second, 'guest-a', GameOnwardKind::Post);
    metricsWentOn($second, 'guest-b', GameOnwardKind::Post);
    metricsWentOn($second, 'guest-b', GameOnwardKind::Post);
    // Sent to the feed, finished without going on, sent by another version, before the window, or a logged-in player: none of them.
    metricsWentOn($first, 'guest-c', GameOnwardKind::Feed);
    metricsAttempt($first, 'guest-d', attributes: metricsCompleted());
    metricsWentOn($third, 'guest-e', GameOnwardKind::Post);
    metricsWentOn($first, 'guest-f', GameOnwardKind::Post, '2026-10-30 12:00:00');
    metricsWentOn($first, $player, GameOnwardKind::Post);

    expect(metricsQuery()->sentToPost(metricsScope($first), [$first->id, $second->id]))->toBe(2)
        ->and(metricsQuery()->sentToPost(metricsScope($first), [$first->id]))->toBe(1)
        ->and(metricsQuery()->sentToPost(metricsScope($first), []))->toBe(0)
        ->and(metricsQuery()->sentToPost(metricsScope($first, GameAudience::Authenticated), [$first->id, $second->id]))->toBe(1);
});

it('refuses to split the figures by anything else', function () {
    $version = liveGame(publishableGameDraft(rounds: 1));

    metricsQuery()->funnels(metricsScope($version), 'city');
})->throws(InvalidArgumentException::class);

it('counts how many started attempts answered each round, and the completed attempts by result', function () {
    $version = liveGame(publishableGameDraft(rounds: 3));
    $answer = fn (GameAttempt $attempt, int $round) => GameAnswer::query()->forceCreate([
        'attempt_id' => $attempt->id, 'round_index' => $round, 'round_key' => "r{$round}", 'option_position' => 0, 'outcome' => 'correct', 'points' => 1, 'answered_at' => now(),
    ]);

    foreach (['guest-a', 'guest-b', 'guest-c'] as $index => $guest) {
        $attempt = metricsAttempt($version, $guest, attributes: $index === 0 ? metricsCompleted('oracle') : ($index === 1 ? metricsCompleted('rebel') : []));

        foreach (range(0, $index === 2 ? 0 : 2) as $round) {
            $answer($attempt, $round);
        }
    }

    // Started before the window: not counted.
    $answer(metricsAttempt($version, 'guest-d', '2026-10-30 12:00:00', metricsCompleted('oracle')), 0);

    expect(metricsQuery()->reach(metricsScope($version), $version->id))->toBe([0 => 3, 1 => 2, 2 => 2])
        ->and(metricsQuery()->results(metricsScope($version), $version->id))->toEqualCanonicalizing(['oracle' => 1, 'rebel' => 1]);
});

it('counts the attempts started from each traffic period', function () {
    $draft = publishableGameDraft(rounds: 1);
    $version = liveGame($draft);
    $period = GameTrafficPeriod::query()->where('game_id', $version->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    // An earlier allocation of the same audience, over before the current one began.
    $earlier = GameTrafficPeriod::factory()->create([
        'game_id' => $version->game_id, 'started_at' => '2026-10-01 00:00:00', 'ended_at' => '2026-10-15 00:00:00', 'started_by_user_id' => $draft['admin']->id,
    ]);
    $assign = fn (string $guest, GameTrafficPeriod $in): GameAssignment => GameAssignment::query()->forceCreate([
        'game_id' => $version->game_id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest),
        'version_id' => $version->id, 'period_id' => $in->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);
    $first = $assign('guest-a', $period);
    // A second visitor of the same period, counted in it with the first.
    $second = $assign('guest-b', $period);
    $old = $assign('guest-c', $earlier);

    metricsAttempt($version, 'guest-a', attributes: ['assignment_id' => $first->id, ...metricsCompleted()]);
    metricsAttempt($version, 'guest-a', attributes: ['assignment_id' => $first->id]);
    metricsAttempt($version, 'guest-b', attributes: ['assignment_id' => $second->id]);
    metricsAttempt($version, 'guest-c', attributes: ['assignment_id' => $old->id, ...metricsCompleted()]);
    // A replay with no assignment belongs to no period.
    metricsAttempt($version, 'guest-a');

    // The query sets no order: compared by period, whichever row came first.
    $periods = metricsQuery()->periods((int) $version->game_id);
    ksort($periods);
    $expected = [$period->id => ['started' => 3, 'completed' => 1], $earlier->id => ['started' => 1, 'completed' => 1]];
    ksort($expected);

    expect($periods)->toBe($expected);
});

it('counts the attempts of one version in each traffic period, when one is named', function () {
    $draft = publishableGameDraft(rounds: 1);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $period = GameTrafficPeriod::query()->where('game_id', $first->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    $assign = fn (string $guest, GameVersion $to): GameAssignment => GameAssignment::query()->forceCreate([
        'game_id' => $first->game_id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest),
        'version_id' => $to->id, 'period_id' => $period->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);
    $a = $assign('guest-a', $first);
    $b = $assign('guest-b', $second);

    metricsAttempt($first, 'guest-a', attributes: ['assignment_id' => $a->id, ...metricsCompleted()]);
    metricsAttempt($second, 'guest-b', attributes: ['assignment_id' => $b->id]);
    metricsAttempt($second, 'guest-b', attributes: ['assignment_id' => $b->id, ...metricsCompleted()]);

    expect(metricsQuery()->periods((int) $first->game_id))->toBe([$period->id => ['started' => 3, 'completed' => 2]])
        ->and(metricsQuery()->periods((int) $first->game_id, (int) $first->id))->toBe([$period->id => ['started' => 1, 'completed' => 1]])
        ->and(metricsQuery()->periods((int) $first->game_id, (int) $second->id))->toBe([$period->id => ['started' => 2, 'completed' => 1]]);
});

it('reads the library’s columns for each game with something in the window', function () {
    $first = liveGame(publishableGameDraft(rounds: 1));
    $second = liveGame(publishableGameDraft(rounds: 1));
    $quiet = liveGame(publishableGameDraft(rounds: 1));
    $newcomer = User::factory()->create();

    metricsView($first, 'guest-a');
    metricsView($first, 'guest-b');
    metricsView($first, User::factory()->create());
    $credited = metricsAttempt($first, 'guest-a', attributes: [...metricsCompleted(), 'user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    AccountAttribution::factory()->confirmed()->create(['user_id' => $newcomer->id, 'credited_attempt_id' => $credited->id]);
    metricsAttempt($first, 'guest-b');
    metricsAttempt($second, 'guest-c', attributes: metricsCompleted());
    metricsAttempt($quiet, 'guest-d', '2026-10-01 12:00:00');

    expect(metricsQuery()->library([(int) $first->game_id, (int) $second->game_id, (int) $quiet->game_id], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-09')))->toBe([
        (int) $first->game_id => ['started' => 2, 'completed' => 1, 'visitors' => 2, 'registered' => 1, 'goal_reached' => 0],
        (int) $second->game_id => ['started' => 1, 'completed' => 1, 'visitors' => 0, 'registered' => 0, 'goal_reached' => 0],
    ])->and(metricsQuery()->library([], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-09')))->toBe([]);
});

it('takes a window that ends after it starts', function () {
    new GameMetricsScope(1, GameAudience::Anonymous, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
})->throws(InvalidArgumentException::class);

it('narrows the figures to the plays and visitors that came on a recorded visit of the device and country chosen', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    // A German phone, an American desktop, a phone whose country is not known, and a guest with no visit recorded.
    $german = metricsVisit($version, 'guest-a', TrafficDevice::Mobile, 'DE');
    $american = metricsVisit($version, 'guest-b', TrafficDevice::Desktop, 'US');
    $unknown = metricsVisit($version, 'guest-d', TrafficDevice::Mobile);

    foreach (['guest-a', 'guest-b', 'guest-c', 'guest-d'] as $guest) {
        metricsView($version, $guest);
    }

    $first = metricsAttempt($version, 'guest-a', attributes: ['traffic_visit_id' => $german->id, ...metricsCompleted()]);
    metricsAttempt($version, 'guest-b', attributes: ['traffic_visit_id' => $american->id]);
    metricsAttempt($version, 'guest-c');
    metricsAttempt($version, 'guest-d', attributes: ['traffic_visit_id' => $unknown->id]);
    // On a German visit, but started as the window closed: not counted.
    metricsAttempt($version, 'guest-a', '2026-11-09 00:00:00', ['traffic_visit_id' => $german->id]);
    metricsView($version, 'guest-a', type: GameEventType::ShareClicked, attempt: $first);
    // Another game's visit from the same browser makes nobody a visitor of this one.
    metricsVisit(liveGame(publishableGameDraft(rounds: 1)), 'guest-c', TrafficDevice::Desktop, 'DE');

    $figures = fn (?VisitFilter $visits): array => (fn (GameFunnel $funnel): array => [$funnel->visitors, $funnel->started, $funnel->completed, $funnel->shared])(metricsQuery()->funnels(metricsScope($version, visits: $visits))[0]);

    expect($figures(null))->toBe([4, 4, 1, 1])
        ->and($figures(new VisitFilter(TrafficDevice::Mobile)))->toBe([2, 2, 1, 1])
        ->and($figures(new VisitFilter(TrafficDevice::Desktop)))->toBe([1, 1, 0, 0])
        ->and($figures(new VisitFilter(countries: ['DE'])))->toBe([1, 1, 1, 1])
        ->and($figures(new VisitFilter(TrafficDevice::Desktop, ['DE'])))->toBe([0, 0, 0, 0])
        // A group of the price set: the countries it names.
        ->and($figures(new VisitFilter(countries: ['US', 'GB'])))->toBe([1, 1, 0, 0])
        // The last group: every country the others don't name, and one not known.
        ->and($figures(new VisitFilter(countries: ['DE', 'GB'], otherCountries: true)))->toBe([2, 2, 0, 0])
        // A last group alone holds every visit there is, but still only plays that came on one.
        ->and($figures(new VisitFilter(countries: [], otherCountries: true)))->toBe([3, 3, 1, 1])
        ->and(metricsQuery()->results(metricsScope($version, visits: new VisitFilter(countries: ['US'])), $version->id))->toBe([]);
});

it('counts a guest by the visit a play of theirs came on, though it landed on another game, and only by visits of the window', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $other = liveGame(publishableGameDraft(rounds: 1));
    // A paid click that landed on another game holds the browser: the play here is counted on it.
    $held = metricsVisit($other, 'guest-e', TrafficDevice::Mobile, 'FR');
    metricsView($version, 'guest-e');
    metricsAttempt($version, 'guest-e', attributes: ['traffic_visit_id' => $held->id]);
    // A German visit long before the window and a French one inside it: the guest is French here.
    metricsVisit($version, 'guest-f', TrafficDevice::Desktop, 'DE', ['arrived_at' => '2026-10-10 10:00:00', 'last_seen_at' => '2026-10-10 10:30:00', 'network_day' => '2026-10-10']);
    metricsVisit($version, 'guest-f', TrafficDevice::Desktop, 'FR');
    metricsView($version, 'guest-f');

    $funnel = fn (VisitFilter $visits): array => (fn (GameFunnel $f): array => [$f->visitors, $f->started])(metricsQuery()->funnels(metricsScope($version, visits: $visits))[0]);

    expect($funnel(new VisitFilter(TrafficDevice::Mobile)))->toBe([1, 1])
        ->and($funnel(new VisitFilter(countries: ['FR'])))->toBe([2, 1])
        ->and($funnel(new VisitFilter(countries: ['DE'])))->toBe([0, 0])
        ->and(collect(metricsQuery()->funnels(metricsScope($version, visits: new VisitFilter(countries: ['DE', 'FR'])), 'country'))->keys()->all())->toBe(['FR']);
});

it('narrows logged-in players by the visit a play of theirs came on, or by a browser they signed in on', function () {
    $version = liveGame(publishableGameDraft(rounds: 1));
    [$playing, $looking, $unseen] = User::factory()->count(3)->create()->all();
    // One played on a phone; one only looked, on a desktop browser they signed in on; one has no visit at all.
    $phone = metricsVisit($version, 'browser-1', TrafficDevice::Mobile, 'DE');
    metricsVisit($version, 'browser-2', TrafficDevice::Desktop, 'DE');
    AccountBrowser::query()->insert(['user_id' => $looking->id, 'visitor_hash' => hash('sha256', 'browser-2'), 'linked_at' => '2026-11-01 00:00:00']);

    foreach ([$playing, $looking, $unseen] as $player) {
        metricsView($version, $player);
    }

    metricsAttempt($version, $playing, attributes: ['traffic_visit_id' => $phone->id]);
    metricsAttempt($version, $unseen);

    $figures = fn (?VisitFilter $visits): array => (fn (GameFunnel $funnel): array => [$funnel->visitors, $funnel->started])(metricsQuery()->funnels(metricsScope($version, GameAudience::Authenticated, visits: $visits))[0]);

    expect($figures(null))->toBe([3, 2])
        ->and($figures(new VisitFilter(TrafficDevice::Mobile)))->toBe([1, 1])
        ->and($figures(new VisitFilter(TrafficDevice::Desktop)))->toBe([1, 0])
        ->and($figures(new VisitFilter(countries: ['DE'])))->toBe([2, 1])
        ->and(metricsQuery()->visitors(metricsScope($version, GameAudience::Authenticated, visits: new VisitFilter(TrafficDevice::Desktop))))->toBe(1);
});

it('splits the figures by the device, country and country group of the visits behind them, a visitor once in each place they came from', function () {
    $version = liveGame(publishableGameDraft(rounds: 1));
    $german = metricsVisit($version, 'guest-a', TrafficDevice::Mobile, 'DE');
    // The same guest again, later, from a desktop in Austria.
    $austrian = metricsVisit($version, 'guest-a', TrafficDevice::Desktop, 'AT');
    $american = metricsVisit($version, 'guest-b', TrafficDevice::Mobile, 'US');
    $unknown = metricsVisit($version, 'guest-c', TrafficDevice::Desktop);

    foreach (['guest-a', 'guest-b', 'guest-c', 'guest-d'] as $guest) {
        metricsView($version, $guest);
    }

    metricsAttempt($version, 'guest-a', attributes: ['traffic_visit_id' => $german->id, ...metricsCompleted()]);
    metricsAttempt($version, 'guest-a', attributes: ['traffic_visit_id' => $austrian->id]);
    metricsAttempt($version, 'guest-b', attributes: ['traffic_visit_id' => $american->id]);
    metricsAttempt($version, 'guest-b', attributes: ['traffic_visit_id' => $american->id]);
    metricsAttempt($version, 'guest-c', attributes: ['traffic_visit_id' => $unknown->id]);
    // No visit: in no row of a visit's.
    metricsAttempt($version, 'guest-d');

    $rows = fn (string $by, array $groups = []): array => array_map(
        fn (GameFunnel $funnel): array => [$funnel->visitors, $funnel->started, $funnel->completed, $funnel->replayers],
        metricsQuery()->funnels(metricsScope($version), $by, $groups),
    );

    expect($rows('device'))->toBe(['desktop' => [2, 2, 0, 0], 'mobile' => [2, 3, 1, 1]])
        ->and($rows('country'))->toBe(['' => [1, 1, 0, 0], 'AT' => [1, 1, 0, 0], 'DE' => [1, 1, 1, 0], 'US' => [1, 2, 0, 1]])
        // Tier 1 names Germany and Austria, Tier 2 nobody's country; the last group holds America and the unknown country.
        ->and($rows('group', [['DE', 'AT'], ['FR']]))->toBe([0 => [1, 2, 1, 1], 2 => [2, 3, 0, 1]])
        // A group naming no country takes none.
        ->and($rows('group', [[]]))->toBe([1 => [3, 5, 1, 2]])
        // Narrowed to a phone and split by country: the phone visits alone.
        ->and(array_keys(metricsQuery()->funnels(metricsScope($version, visits: new VisitFilter(TrafficDevice::Mobile)), 'country')))->toBe(['DE', 'US']);
});

it('counts, per traffic period and version, how far the visitors it assigned got', function () {
    $this->travelTo('2026-11-18 12:00:00');
    $draft = publishableGameDraft(rounds: 1);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $period = GameTrafficPeriod::query()->where('game_id', $first->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    $maturedBefore = CarbonImmutable::parse('2026-11-11 00:00:00');

    // V1: one visitor who never started, one who started, one who saw the result and left an email,
    // one who reached the goal with a matured, activated account, and one whose account isn't confirmed.
    periodVisitor($period, $first);
    periodVisitor($period, $first, ['started' => true]);
    periodVisitor($period, $first, ['result' => true, 'email' => true]);
    periodVisitor($period, $first, ['goal' => true, 'account' => '2026-11-02 10:00:00', 'activated' => true]);
    periodVisitor($period, $first, ['account' => '2026-11-03 10:00:00', 'confirmed' => false]);
    // V2: a visitor whose account signed up too recently to have matured, though active already.
    $late = periodVisitor($period, $second, ['completed' => true, 'account' => '2026-11-15 10:00:00', 'activated' => true]);
    // A second attempt from the same assignment counts the visitor once.
    GameAttempt::factory()->create(['version_id' => $second->id, 'assignment_id' => $late->id, 'visitor_hash' => $late->visitor_hash, ...metricsCompleted()]);

    $funnels = metricsQuery()->periodFunnel([(int) $period->id], $maturedBefore);
    $figures = fn (PeriodVersionFunnel $f): array => [$f->assigned, $f->started, $f->completed, $f->resultViewed, $f->emailed, $f->goal, $f->accounts, $f->matured, $f->activated];

    expect(array_keys($funnels))->toBe([(int) $period->id])
        ->and($figures($funnels[$period->id][$first->id]))->toBe([5, 4, 1, 1, 1, 1, 1, 1, 1])
        ->and($figures($funnels[$period->id][$second->id]))->toBe([1, 1, 1, 0, 0, 0, 1, 0, 0])
        ->and(metricsQuery()->periodFunnel([], $maturedBefore))->toBe([]);
});

it('keeps a visitor assigned again in a later period in each period’s own figures, and an account in the period of the attempt it is credited to', function () {
    $this->travelTo('2026-11-18 12:00:00');
    $draft = publishableGameDraft(rounds: 1);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);
    $earlier = GameTrafficPeriod::query()->where('game_id', $first->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    $earlier->forceFill(['ended_at' => now()])->save();
    $later = GameTrafficPeriod::factory()->create(['game_id' => $first->game_id, 'started_at' => now()]);
    $later->versions()->attach($second->id, ['share_percent' => 100]);

    // The same browser: played V1 in the first period, then given V2 in the next and signed up from that play.
    $before = periodVisitor($earlier, $first, ['completed' => true]);
    $after = GameAssignment::query()->forceCreate([
        'game_id' => $first->game_id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => $before->visitor_hash,
        'version_id' => $second->id, 'period_id' => $later->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);
    $credited = GameAttempt::factory()->create(['version_id' => $second->id, 'assignment_id' => $after->id, 'visitor_hash' => $before->visitor_hash]);
    AccountAttribution::factory()->confirmed()->create(['credited_attempt_id' => $credited->id, 'signed_up_at' => '2026-11-01 10:00:00']);

    $funnels = metricsQuery()->periodFunnel([(int) $earlier->id, (int) $later->id], CarbonImmutable::parse('2026-11-11 00:00:00'));

    expect([$funnels[$earlier->id][$first->id]->assigned, $funnels[$earlier->id][$first->id]->completed, $funnels[$earlier->id][$first->id]->accounts])->toBe([1, 1, 0])
        ->and(array_keys($funnels[$earlier->id]))->toBe([$first->id])
        ->and([$funnels[$later->id][$second->id]->assigned, $funnels[$later->id][$second->id]->started, $funnels[$later->id][$second->id]->completed, $funnels[$later->id][$second->id]->accounts, $funnels[$later->id][$second->id]->matured])->toBe([1, 1, 0, 1, 1]);
});

it('counts an account as matured once its seven days after the day it signed up are over', function () {
    $this->travelTo('2026-11-18 12:00:00');
    $version = liveGame(publishableGameDraft(rounds: 1));
    $period = GameTrafficPeriod::query()->where('game_id', $version->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();

    // Signed up on 10 Nov: days 1 to 7 ran to the end of 17 Nov. On 11 Nov: day 7 is today.
    periodVisitor($period, $version, ['account' => '2026-11-10 23:59:59', 'activated' => true]);
    periodVisitor($period, $version, ['account' => '2026-11-11 00:00:00', 'activated' => true]);

    $funnel = metricsQuery()->periodFunnel([(int) $period->id], ActivityDay::maturedBefore(CarbonImmutable::now(), 7))[$period->id][$version->id];

    expect([$funnel->accounts, $funnel->matured, $funnel->activated])->toBe([2, 1, 1]);
});
