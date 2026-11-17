<?php

use App\Actions\Auth\LinkSocialAccountAction;
use App\Actions\Auth\RegisterSocialUserAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Actions\Traffic\RecordAccountBonusAction;
use App\Data\Auth\SocialIdentity;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameEventType;
use App\Enums\SocialProvider;
use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Livewire\Games\PlayGame;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\ProjectSettings;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->token = str_repeat('a', 40);
    $this->hash = browserHashOf($this->token);
    $this->cookie = (string) config('games.visitor_cookie.name');
});

/** The one-round game, with this goal, finished by the browser holding the token. */
function finishGameIn(string $token, string $goal = 'account'): GameAttempt
{
    $draft = publishableGameDraft(1);
    $draft['flow']->forceFill(['goal' => $goal])->save();
    liveGame($draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next');

    return GameAttempt::query()->latest('id')->firstOrFail();
}

function registerIn(string $token, string $email = 'new@example.com', string $page = '/posts/123'): User
{
    test()->withCookie((string) config('games.visitor_cookie.name'), $token)
        ->post('/register', ['name' => 'New Member', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'] + authModalFields('register', $page))
        ->assertRedirect();
    auth()->logout();

    return User::query()->where('email', $email)->sole();
}

it('credits a new account to the game its browser just finished, and counts it once confirmed', function () {
    $attempt = finishGameIn($this->token);

    $member = registerIn($this->token);
    $attribution = AccountAttribution::query()->sole();

    expect($attribution->user_id)->toBe($member->id)
        ->and($attribution->credited_attempt_id)->toBe($attempt->id)
        ->and($attribution->signup_method)->toBe(AccountSignupMethod::Email)
        ->and($attribution->signup_place)->toBe(AccountSignupPlace::Post)
        ->and($attribution->confirmed_at)->toBeNull()
        ->and($attempt->fresh()->goal_reached_at)->toBeNull()
        ->and(AccountBrowser::query()->where('user_id', $member->id)->where('visitor_hash', $this->hash)->exists())->toBeTrue();

    app(VerifyEmailAction::class)->execute($member, sha1($member->email));
    app(VerifyEmailAction::class)->execute($member->fresh(), sha1($member->email));

    expect($attribution->fresh()->confirmed_at)->not->toBeNull()
        ->and($attempt->fresh()->goal_reached_at)->not->toBeNull()
        ->and(GameEvent::query()->where('type', GameEventType::AccountConfirmed)->count())->toBe(1)
        ->and(GameEvent::query()->where('type', GameEventType::GoalReached)->count())->toBe(1);
});

it('credits one play with one account', function () {
    $attempt = finishGameIn($this->token);

    registerIn($this->token, 'first@example.com');
    registerIn($this->token, 'second@example.com');

    expect(AccountAttribution::query()->orderBy('id')->pluck('credited_attempt_id')->all())->toBe([$attempt->id, null]);
});

it('credits the paid click holding the browser, and gives it the account once confirmed', function () {
    recordTraffic();
    $click = TrafficVisit::factory()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subHour()]);

    $member = registerIn($this->token, page: '/');
    $attribution = AccountAttribution::query()->sole();

    expect($attribution->credited_visit_id)->toBe($click->id)
        ->and($click->fresh()->signup_at)->not->toBeNull()
        ->and($click->fresh()->signup_method)->toBe(AccountSignupMethod::Email)
        ->and($click->fresh()->signup_after_seconds)->toBeGreaterThanOrEqual(3600)
        ->and($click->fresh()->account_at)->toBeNull();

    app(VerifyEmailAction::class)->execute($member, sha1($member->email));

    expect($click->fresh()->account_at)->not->toBeNull()
        ->and($click->fresh()->account_after_seconds)->toBeGreaterThanOrEqual(3600);
});

it('tells a click whose window closed that the account came late, and credits the unpaid visit instead', function () {
    recordTraffic();
    $expired = TrafficVisit::factory()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subDays(9), 'holds_until' => now()->subDays(2)]);
    $unpaid = TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subHour()]);

    registerIn($this->token);
    $attribution = AccountAttribution::query()->sole();

    expect($attribution->late)->toBeTrue()
        ->and($attribution->expired_visit_id)->toBe($expired->id)
        ->and($attribution->credited_visit_id)->toBe($unpaid->id)
        ->and($expired->fresh()->late_signup_at)->not->toBeNull()
        ->and($expired->fresh()->signup_at)->toBeNull();
});

it('credits the unpaid visit a game opened, and never takes the sign-in that follows the sign-up for a returning member', function () {
    recordTraffic();
    $game = Game::factory()->create();
    $unpaid = TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['visitor_hash' => $this->hash, 'game_id' => $game->id, 'arrived_at' => now()->subHour(), 'opened_at' => now()->subHour()]);

    registerIn($this->token);

    expect(AccountAttribution::query()->sole()->credited_visit_id)->toBe($unpaid->id)
        ->and($unpaid->fresh())->signup_at->not->toBeNull()->login_at->toBeNull()->known->toBeFalse();
});

it('confirms an account signed up through Google at once', function () {
    $member = app(RegisterSocialUserAction::class)->execute(new SocialIdentity(SocialProvider::Google, 'g-1', 'g@example.com', 'Gina', null, false));
    $attribution = AccountAttribution::query()->where('user_id', $member->id)->sole();

    expect($attribution->signup_method)->toBe(AccountSignupMethod::Google)
        ->and($attribution->confirmed_at)->not->toBeNull();
});

it('confirms a new account when it links Google from its profile', function () {
    $attempt = finishGameIn($this->token);
    $member = registerIn($this->token);

    app(LinkSocialAccountAction::class)->execute($member, new SocialIdentity(SocialProvider::Google, 'g-2', 'new@example.com', 'New Member', null, false));

    expect(AccountAttribution::query()->sole()->confirmed_at)->not->toBeNull()
        ->and($attempt->fresh()->goal_reached_at)->not->toBeNull();
});

it('credits a new account only to a flow whose goal takes it', function () {
    $attempt = finishGameIn($this->token, 'shared');
    $member = registerIn($this->token);

    app(VerifyEmailAction::class)->execute($member, sha1($member->email));

    expect($attempt->fresh()->goal_reached_at)->toBeNull()
        ->and(GameEvent::query()->where('type', GameEventType::AccountConfirmed)->count())->toBe(1);
});

it('counts a sign-in to an existing confirmed account for the goal “Account confirmed”, not for new accounts', function () {
    recordTraffic();
    $existing = User::factory()->create(['password' => bcrypt('password')]);
    $attempt = finishGameIn($this->token);
    $visit = TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $this->hash]);
    $other = finishGameIn(str_repeat('b', 40), 'new_account');

    $this->withCookie($this->cookie, $this->token)->post('/login', ['email' => $existing->email, 'password' => 'password'])->assertRedirect();

    expect($attempt->fresh()->goal_reached_at)->not->toBeNull()
        ->and($other->fresh()->goal_reached_at)->toBeNull()
        ->and($visit->fresh()->login_at)->not->toBeNull()
        ->and($visit->fresh()->known)->toBeTrue()
        ->and($visit->fresh()->known_reason)->toBe(TrafficKnownReason::Login)
        ->and(AccountAttribution::query()->count())->toBe(0)
        ->and(AccountBrowser::query()->where('user_id', $existing->id)->where('visitor_hash', $this->hash)->exists())->toBeTrue();
});

it('keeps a paid click that brought an account when another account signs in on the same browser', function () {
    recordTraffic();
    $click = TrafficVisit::factory()->create(['visitor_hash' => $this->hash, 'arrived_at' => now()->subHour(), 'holds_until' => now()->addDays(7), 'opened_at' => now()->subHour()]);
    registerIn($this->token);
    $existing = User::factory()->create(['password' => bcrypt('password')]);

    $this->withCookie($this->cookie, $this->token)->post('/login', ['email' => $existing->email, 'password' => 'password'])->assertRedirect();

    expect($click->fresh())->signup_at->not->toBeNull()->login_at->toBeNull()->known->toBeFalse();
});

it('records the first post within 30 days and a return on another day, once each', function () {
    recordTraffic();
    $click = TrafficVisit::factory()->create(['visitor_hash' => $this->hash]);
    $member = registerIn($this->token);
    $bonuses = app(RecordAccountBonusAction::class);

    $bonuses->returned($member->id, now()->addHours(2)->toImmutable());
    expect(AccountAttribution::query()->sole()->returned_at)->toBeNull();

    $bonuses->firstPost($member->id, now()->addDays(3)->toImmutable());
    $bonuses->firstPost($member->id, now()->addDays(4)->toImmutable());
    $bonuses->returned($member->id, now()->addDay()->addHours(9)->toImmutable());

    $attribution = AccountAttribution::query()->sole();

    expect($attribution->first_post_at->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and($attribution->returned_at)->not->toBeNull()
        ->and($click->fresh()->post_published_at)->not->toBeNull()
        ->and($click->fresh()->returned_at)->not->toBeNull();
});

it('looks for a return again once the account is old enough, not only the next day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 20:00:00'));
    recordTraffic();
    $member = registerIn($this->token);
    $member->forceFill(['email_verified_at' => now()])->save();

    // 01:00 the next day: another day, but only five hours on. 10:00: fourteen hours on — a return.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 01:00:00'));
    $this->actingAs($member)->get('/')->assertOk();
    expect(AccountAttribution::query()->sole()->returned_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->get('/')->assertOk();
    expect(AccountAttribution::query()->sole()->returned_at)->not->toBeNull();
});

it('counts no first post after 30 days', function () {
    $member = registerIn($this->token);

    app(RecordAccountBonusAction::class)->firstPost($member->id, now()->addDays(31)->toImmutable());

    expect(AccountAttribution::query()->sole()->first_post_at)->toBeNull();
});

it('marks the visit that saw a sign-up form in the game', function () {
    recordTraffic();
    $draft = publishableGameDraft(1);
    liveGame($draft);
    $visit = TrafficVisit::factory()->unpaid()->create(['visitor_hash' => $this->hash, 'game_id' => $draft['game']->id]);

    $play = Livewire::withCookie($this->cookie, $this->token)->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('continue');
    GameAttempt::query()->sole()->forceFill(['traffic_visit_id' => $visit->id])->save();
    $play->call('choose', 0)->call('next');

    expect($visit->fresh()->signup_form_at)->not->toBeNull();
});

it('issues no visitor cookie on sign-up while visits are not recorded', function () {
    $this->post('/register', ['name' => 'New Member', 'email' => 'quiet@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertCookieMissing($this->cookie);

    expect(AccountAttribution::query()->sole()->visitor_hash)->toBeNull();
});

it('notices a new account coming back on another day, from any page', function () {
    $member = User::factory()->create();
    AccountAttribution::factory()->create(['user_id' => $member->id, 'signed_up_at' => now()->subDay()->subHours(2)]);

    $this->actingAs($member)->get('/')->assertOk();

    expect(AccountAttribution::query()->sole()->returned_at)->not->toBeNull();
});
