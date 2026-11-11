<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameStatus;
use App\Livewire\Games\PlayGame;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameRoundOption;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->version = liveGame($this->draft);
    $this->slug = $this->draft['game']->slug;
});

/** A guest's browser, by its games cookie. */
function guestGame(string $slug, string $token = 'guesttokenguesttokenguesttokenguesttoken'): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)->test(PlayGame::class, ['slug' => $slug]);
}

it('opens a live game at its address, and nothing while games are off or the game is a draft', function () {
    $page = $this->get('/games/'.$this->slug)->assertOk()->assertSee('Start playing')
        ->assertSee('<title>'.e($this->draft['game']->title).' · RateGuru</title>', false);

    // Livewire roots the component on the game's own element, the one that asks once the page runs.
    expect(preg_match('/<div[^>]*wire:name="games\.play-game"[^>]*>/', $page->getContent(), $root))->toBe(1)
        ->and($root[0])->toContain('data-testid="game-page"')
        ->and($root[0])->toContain('wire:init="init"');

    allowGames(false);
    $this->get('/games/'.$this->slug)->assertNotFound();

    allowGames();
    $this->draft['game']->fresh()->forceFill(['status' => GameStatus::Draft])->save();
    $this->get('/games/'.$this->slug)->assertNotFound();
});

it('describes the game when its link is shared, with the site’s own picture until the game has a cover', function () {
    $game = $this->draft['game'];
    $postPictures = storeGamePostPictures();
    $page = $this->get('/games/'.$this->slug)->assertOk();

    $page->assertSee('<meta property="og:title" content="'.e($game->title).'">', false)
        ->assertSee('<meta name="description" content="'.e($game->description).'">', false)
        ->assertSee('<meta property="og:url" content="'.route('games.play', ['slug' => $this->slug]).'">', false)
        ->assertSee('<meta property="og:image" content="'.asset(config('share.open_graph.fallback_path')).'">', false);

    // Never a picture of a post the game shows.
    foreach ($postPictures as $path) {
        $page->assertDontSee($path, false);
    }
});

it('asks a guest to sign in while guest viewing is off', function () {
    $settings = ProjectSettings::query()->find(1);
    $settings->forceFill(['feature_flags' => [...$settings->feature_flags, 'allow_guest_viewing' => false]])->save();
    app(ProjectSettingsManager::class)->flush();

    $this->get('/games/'.$this->slug)->assertRedirect(route('login'));
});

it('lets a guest play to the result, scored on the server', function () {
    $page = guestGame($this->slug)->call('init');

    expect(GameAssignment::query()->sole()->version_id)->toBe($this->version->id)
        ->and(GameEvent::query()->where('type', GameEventType::View)->count())->toBe(1);

    $page->call('continue')->assertSee('Pair 1 of 2');
    // Starting is not another view of the page.
    expect(GameEvent::query()->where('type', GameEventType::View)->count())->toBe(1);
    $page->call('choose', 0)->assertSee('You’re with the crowd.')->assertSee('90% upvoted it');
    $page->call('next')->assertSee('Pair 2 of 2');
    $page->call('choose', 1)->assertSee('Bold call.');
    $page->call('next')->assertSee('data-testid="game-step-full_result"', false)->assertSee('You matched the crowd in 1 of 2 picks');

    $attempt = GameAttempt::query()->sole();

    expect($attempt->status)->toBe(GameAttemptStatus::Completed)
        ->and($attempt->audience)->toBe(GameAudience::Anonymous)
        ->and($attempt->matches)->toBe(1)
        ->and($attempt->score)->toBe(1)
        ->and($attempt->profile_key)->toBe('independent')
        ->and($attempt->user_id)->toBeNull()
        ->and(GameEvent::query()->pluck('type')->map->value->all())->toContain('started', 'round_answered', 'completed');
});

it('records a pick once, however often it is sent', function () {
    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('choose', 0)->call('choose', 1);

    expect(GameAnswer::query()->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->rounds_answered)->toBe(1);
});

it('keeps the reveal out of the browser’s hands', function () {
    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->set('reveal', null);
})->throws(CannotUpdateLockedPropertyException::class);

it('answers only the round on screen, so a tab left behind answers none it never saw', function () {
    $page = guestGame($this->slug)->call('init')->call('continue');
    $stale = guestGame($this->slug)->assertSee('Pair 1 of 2');

    $page->call('choose', 0, 0)->call('next')->assertSee('Pair 2 of 2');
    $stale->call('choose', 1, 0)->assertSet('reveal', null)->assertSee('Pair 2 of 2');

    expect(GameAnswer::query()->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->rounds_answered)->toBe(1);

    // Once another tab has finished the game, a pick goes on to where the attempt stands.
    $page->call('choose', 0, 1)->call('next')->assertSee('data-testid="game-step-full_result"', false);
    $stale->call('choose', 1, 1)->assertSee('data-testid="game-step-full_result"', false);

    expect(GameAnswer::query()->count())->toBe(2);
});

it('sends the round on screen with a pick, and leaves the round open to another tap when no reveal comes back', function () {
    guestGame($this->slug)->call('init')->call('continue')->assertSeeHtml('x-data="rgGameRound({ round: 0, ');

    expect((string) file_get_contents(resource_path('js/games.js')))
        ->toContain('wire.choose(position, round).then(reopen, reopen);')
        ->toMatch('/if \(wire\.reveal === null\) \{\s+this\.picked = null;/');
});

it('never steps past the game but by its last reveal', function () {
    $page = guestGame($this->slug)->call('init')->call('continue');
    $gameStep = $page->get('stepKey');

    $page->call('continue')->assertSet('stepKey', $gameStep)->assertSee('Pair 1 of 2');
    $page->call('choose', 0)->call('continue')->assertSet('stepKey', $gameStep);

    expect(GameAttempt::query()->sole()->current_step_key)->toBe($gameStep);
});

it('describes a revealed picture by what the reveal says of it, read after its name', function () {
    $dom = livewireDom(guestGame($this->slug)->call('init')->call('continue')->call('choose', 0));
    $description = fn (int $position): string => collect(explode(' ', (string) $dom->query('//button[@data-testid="game-option-'.$position.'"]')->item(0)?->getAttribute('aria-describedby')))
        ->map(fn (string $id): string => $id === '' ? '' : trim((string) $dom->query('//*[@id="'.$id.'"]')->item(0)?->textContent))
        ->implode(' ');

    expect($description(0))->toContain('90% upvoted it')->toContain('Crowd’s pick')->toContain('You')
        ->and($description(1))->toContain('60% upvoted it')->not->toContain('You');
});

it('keeps the progress dots from the screen reader, which hears the pair’s number instead', function () {
    expect(livewireFragment(guestGame($this->slug)->call('init')->call('continue'), '//ol'))
        ->toStartWith('<ol class="rg-game__dots" aria-hidden="true">');
});

it('resumes an attempt where it stands after a refresh', function () {
    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('next');

    guestGame($this->slug)->assertSee('Pair 2 of 2');
});

it('never goes on with an attempt another browser started', function () {
    $page = guestGame($this->slug)->call('init')->call('continue');
    session()->forget('games.attempts');

    $page->call('choose', 0);

    expect(GameAnswer::query()->count())->toBe(0);
});

it('plays for a signed-in person as a logged-in player', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('next')->call('choose', 0)->call('next')->assertSee('You matched the crowd in 2 of 2 picks');

    expect(GameAttempt::query()->sole()->user_id)->toBe($user->id)
        ->and(GameAttempt::query()->sole()->audience)->toBe(GameAudience::Authenticated);
});

it('lets a started attempt finish while the game is paused, and starts none', function () {
    $page = guestGame($this->slug)->call('init')->call('continue');
    $this->draft['game']->fresh()->forceFill(['status' => GameStatus::Paused])->save();

    $page->call('choose', 0)->call('next')->call('choose', 0)->call('next')->assertSee('data-testid="game-step-full_result"', false);

    // Another browser: another session, another cookie.
    session()->flush();
    guestGame($this->slug, 'othertokenothertokenothertokenothertoken')
        ->assertSeeHtml('<h1 class="rg-game__title rg-game__title--sm">This game is taking a break. Come back soon.</h1>');
});

it('stops showing a version once a post it shows leaves the site', function () {
    $page = guestGame($this->slug)->call('init')->call('continue');
    GameRoundOption::query()->where('position', 0)->first()->post->forceFill(['status' => 'hidden'])->save();

    $page->call('choose', 0)->assertSee('This game can’t be played right now.');

    expect(GameAnswer::query()->count())->toBe(1);

    session()->flush();
    guestGame($this->slug, 'thirdtokenthirdtokenthirdtokenthirdtoken')->assertSee('This game can’t be played right now.');
});

it('starts another attempt on Play again', function () {
    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('next')->call('choose', 0)->call('next')->call('replay')->assertSee('Pair 1 of 2');

    expect(GameAttempt::query()->count())->toBe(2)
        ->and(GameEvent::query()->where('type', GameEventType::Replay)->count())->toBe(1);
});

it('counts a guest’s share to a provider on their attempt, sharing the profile’s page', function () {
    $page = guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('next')->call('choose', 0)->call('next');
    $attempt = GameAttempt::query()->sole();
    $link = route('games.player-share', ['slug' => $this->slug, 'attempt' => $attempt->public_id, 'provider' => 'x']);

    $page->assertSeeHtml(e($link));

    expect($this->get($link)->headers->get('Location'))
        ->toContain(urlencode(route('games.result', ['slug' => $this->slug, 'version' => 1, 'profile' => $attempt->profile_key])))
        ->and(GameEvent::query()->where('type', GameEventType::ShareClicked)->sole()->attempt_id)->toBe($attempt->id);
});

it('lets a long byline wrap on the share card rather than be cut', function () {
    expect((string) file_get_contents(resource_path('css/app.css')))
        ->toContain(".rg-game__share-card-host {\n        display: inline-block;\n        max-width: 100%;\n        overflow-wrap: anywhere;\n    }");
});

it('gives a person the attempt they played as a guest when they register', function () {
    guestGame($this->slug)->call('init')->call('continue')->call('choose', 0)->call('next')->call('choose', 0)->call('next');
    $user = User::factory()->create();
    request()->setLaravelSession(session()->driver());

    event(new Registered($user));
    event(new Login('web', $user, false));

    $attempt = GameAttempt::query()->sole();

    expect($attempt->user_id)->toBe($user->id)
        ->and($attempt->account_outcome->value)->toBe('registered')
        ->and(GameEvent::query()->where('type', GameEventType::AccountAttributed)->value('provider'))->toBe('registered');
});

it('tells a sign-in apart from a new account, and gives a Persona nothing', function () {
    guestGame($this->slug)->call('init')->call('continue');
    $persona = User::factory()->persona()->create();
    request()->setLaravelSession(session()->driver());

    event(new Login('web', $persona, false));
    expect(GameAttempt::query()->sole()->user_id)->toBeNull();

    $user = User::factory()->create();
    event(new Login('web', $user, false));
    expect(GameAttempt::query()->sole()->account_outcome->value)->toBe('signed_in');
});

it('keeps a visitor on the version they were given when the shares change', function () {
    $v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    guestGame($this->slug)->call('init');
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Anonymous, [$this->version->id => 50, $v2->id => 50]);

    guestGame($this->slug)->call('init');

    expect(GameAssignment::query()->whereNotNull('active_key')->sole()->version_id)->toBe($this->version->id);
});

it('draws the game under its own bar — the site\'s name and the way out — and not the site\'s header, keeping the sign-in dialog', function () {
    $this->get('/games/'.$this->slug)
        ->assertOk()
        ->assertSee('data-testid="game-brand"', false)
        ->assertSee('data-testid="game-skip-to-feed"', false)
        ->assertSee('data-testid="auth-modal-root"', false)
        ->assertDontSee('data-testid="app-header"', false)
        ->assertDontSee('data-testid="mobile-nav-drawer"', false);
});
