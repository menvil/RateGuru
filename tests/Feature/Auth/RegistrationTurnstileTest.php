<?php

use App\Actions\Auth\ScreenRegistrationAction;
use App\Enums\TurnstileOutcome;
use App\Exceptions\Auth\TurnstileMisconfiguredException;
use App\Livewire\Games\PreviewGame;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Auth\RegistrationFormStamp;
use App\Support\Auth\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Monolog\LogRecord;

/*
 * Cloudflare Turnstile as the last check on a sign-up. Cloudflare itself is
 * never called: every answer here is one its siteverify endpoint gives.
 */

const SITEVERIFY = 'challenges.cloudflare.com/turnstile/v0/siteverify';

function turnstileAnswers(array $body, int $status = 200): void
{
    Http::preventStrayRequests();
    Http::fake([SITEVERIFY => Http::response($body, $status)]);
}

function turnstilePassed(array $overrides = []): array
{
    return [
        'success' => true,
        'error-codes' => [],
        'challenge_ts' => '2026-11-22T14:56:44.274Z',
        'hostname' => 'localhost',
        'action' => TurnstileVerifier::ACTION,
        ...$overrides,
    ];
}

function challengedSignUp(array $overrides = [], string $page = '/register'): array
{
    return screenedSignUp([TurnstileVerifier::FIELD => 'a-token', ...$overrides], $page);
}

beforeEach(function () {
    config([
        'registration.screening_mode' => 'enforce',
        'services.turnstile.site_key' => 'site-key',
        'services.turnstile.secret_key' => 'secret-key',
    ]);
});

it('lets through a sign-up Cloudflare passes, sending it the token and the secret in the body', function () {
    turnstileAnswers(turnstilePassed());

    $this->post('/register', challengedSignUp())->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://'.SITEVERIFY
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'a-token');
});

it('refuses what Cloudflare does not pass', function (array $answer) {
    turnstileAnswers($answer);

    $this->post('/register', challengedSignUp())
        ->assertSessionHasErrors([ScreenRegistrationAction::ERROR_FIELD => __('auth.register.refused')]);

    $this->assertGuest();
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
})->with([
    'a wrong token' => [['success' => false, 'error-codes' => ['invalid-input-response']]],
    'an expired or reused token' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']]],
    'a token for another action' => [turnstilePassed(['action' => 'login'])],
    'a real token naming no action' => [array_diff_key(turnstilePassed(), ['action' => true])],
    'a token from another site' => [turnstilePassed(['hostname' => 'elsewhere.example'])],
]);

it('refuses a missing or unusable token without asking Cloudflare', function (mixed $token) {
    turnstileAnswers(turnstilePassed());

    $this->post('/register', challengedSignUp([TurnstileVerifier::FIELD => $token]))
        ->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);

    Http::assertNothingSent();
})->with([
    'none' => [null],
    'empty' => [''],
    'not text' => [['a']],
    'longer than Cloudflare issues' => [str_repeat('a', 2049)],
]);

it('does not spend the token on a sign-up another check refuses', function () {
    turnstileAnswers(turnstilePassed());

    $this->post('/register', challengedSignUp([ScreenRegistrationAction::HONEYPOT_FIELD => 'x']))
        ->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);
    $this->post('/register', challengedSignUp(['email' => 'not-an-address']))->assertSessionHasErrors('email');

    Http::assertNothingSent();
});

it('never keeps the token for the next page', function () {
    turnstileAnswers(['success' => false, 'error-codes' => ['invalid-input-response']]);

    $this->post('/register', challengedSignUp(['name' => 'Kept Name']))
        ->assertSessionHasInput('name', 'Kept Name')
        ->assertSessionMissing('_old_input.'.TurnstileVerifier::FIELD);
});

it('only logs a failed challenge in report mode, and the sign-up goes through', function () {
    config(['registration.screening_mode' => 'report']);
    $log = fakeSecurityLog();
    turnstileAnswers(['success' => false, 'error-codes' => ['invalid-input-response']]);

    $this->post('/register', challengedSignUp())->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    $line = collect($log->getRecords())->first(fn (LogRecord $record): bool => $record->message === ScreenRegistrationAction::EVENT);
    expect($line->context)->toMatchArray(['reasons' => ['challenge_failed'], 'refused' => false])
        ->and(json_encode($line->context))->not->toContain('a-token');
});

it('keeps sign-ups going while Cloudflare cannot answer, one an hour per address', function (Closure $outage) {
    $outage();

    $this->post('/register', challengedSignUp())->assertSessionHasNoErrors();
    $this->assertAuthenticated();
    auth()->logout();

    $this->post('/register', challengedSignUp(['email' => 'second@example.com']))
        ->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();

    $this->travel(61)->minutes();
    RateLimiter::clear(RateLimitKey::registration('hour', '127.0.0.1'));

    $this->post('/register', challengedSignUp(['email' => 'third@example.com']))->assertSessionHasNoErrors();
})->with([
    'a timeout' => [fn () => Http::fake([SITEVERIFY => fn () => throw new ConnectionException('timed out')])],
    'a server error' => [fn () => turnstileAnswers([], 503)],
    'an answer that is not JSON' => [fn () => Http::fake([SITEVERIFY => Http::response('<html>', 200)])],
    'its internal error' => [fn () => turnstileAnswers(['success' => false, 'error-codes' => ['internal-error']])],
]);

it('only logs passing the outage limit in report mode, and the sign-up goes through', function () {
    config(['registration.screening_mode' => 'report']);
    $log = fakeSecurityLog();
    turnstileAnswers([], 503);

    $this->post('/register', challengedSignUp())->assertSessionHasNoErrors();
    auth()->logout();
    $this->post('/register', challengedSignUp(['email' => 'second@example.com']))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeTrue();

    $line = collect($log->getRecords())->last(fn (LogRecord $record): bool => $record->message === ScreenRegistrationAction::EVENT);
    expect($line->context)->toMatchArray(['reasons' => ['limit_without_challenge'], 'refused' => false]);
});

it('does not let a missing token pass while Cloudflare is down', function () {
    Http::fake([SITEVERIFY => fn () => throw new ConnectionException('timed out')]);

    $this->post('/register', challengedSignUp([TurnstileVerifier::FIELD => null]))
        ->assertSessionHasErrors(ScreenRegistrationAction::ERROR_FIELD);
});

it('reports a secret Cloudflare refuses, and keeps sign-ups going', function (string $code) {
    Exceptions::fake();
    turnstileAnswers(['success' => false, 'error-codes' => [$code]]);

    $this->post('/register', challengedSignUp())->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    Exceptions::assertReported(fn (TurnstileMisconfiguredException $exception): bool => ! str_contains($exception->getMessage(), 'secret-key'));
})->with(['invalid-input-secret', 'missing-input-secret']);

it('keeps the form and the token out of what it reports about a refused secret', function () {
    Exceptions::fake();
    turnstileAnswers(['success' => false, 'error-codes' => ['invalid-input-secret']]);
    $form = challengedSignUp();
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $this->post('/register', $form);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    Exceptions::assertReported(function (TurnstileMisconfiguredException $reported): bool {
        $argument = fn (string $class, string $function): mixed => collect($reported->getTrace())
            ->first(fn (array $frame): bool => ($frame['class'] ?? null) === $class && $frame['function'] === $function)['args'][0] ?? null;

        return $argument(ScreenRegistrationAction::class, 'execute') instanceof SensitiveParameterValue
            && $argument(ScreenRegistrationAction::class, 'challengeReasons') instanceof SensitiveParameterValue
            && $argument(TurnstileVerifier::class, 'verify') instanceof SensitiveParameterValue;
    });
});

it('treats a limit it cannot count during an outage like any limit it cannot read', function (string $mode) {
    config(['registration.screening_mode' => $mode]);
    $log = fakeSecurityLog();
    turnstileAnswers([], 503);
    $stamp = app(RegistrationFormStamp::class)->issue();
    $this->travel(10)->seconds();

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('cache down'));

    $screen = fn () => app(ScreenRegistrationAction::class)->execute([
        RegistrationFormStamp::FIELD => $stamp,
        TurnstileVerifier::FIELD => 'a-token',
    ], '127.0.0.1');

    $mode === 'report'
        ? expect($screen())->toBe([])
        : expect($screen)->toThrow(ValidationException::class);

    $line = collect($log->getRecords())->first(fn (LogRecord $record): bool => $record->message === ScreenRegistrationAction::EVENT);
    expect($line->context)->toMatchArray(['reasons' => ['limits_unavailable'], 'refused' => $mode === 'enforce']);
})->with(['report', 'enforce']);

it('accepts Cloudflare\'s testing keys anywhere but production', function (string $environment, TurnstileOutcome $outcome) {
    turnstileAnswers(turnstilePassed(['hostname' => 'example.com', 'metadata' => ['result_with_testing_key' => true]]));
    app()->detectEnvironment(fn () => $environment);

    expect(app(TurnstileVerifier::class)->verify('a-token', 'localhost'))->toBe($outcome);
})->with([
    'staging' => ['staging', TurnstileOutcome::Passed],
    'production' => ['production', TurnstileOutcome::Rejected],
]);

it('asks nothing when the keys are not set', function (?string $siteKey, ?string $secretKey) {
    config(['services.turnstile.site_key' => $siteKey, 'services.turnstile.secret_key' => $secretKey]);
    Http::preventStrayRequests();

    $html = $this->get('/register')->getContent();
    expect($html)->not->toContain('data-rg-turnstile');

    $this->post('/register', screenedSignUp())->assertSessionHasNoErrors();

    Http::assertNothingSent();
})->with([
    'neither' => [null, null],
    'no secret' => ['site-key', ''],
    'no site key' => ['', 'secret-key'],
]);

it('draws the widget without loading Cloudflare until a form is shown', function (string $page) {
    $html = $this->get($page)->assertOk()->getContent();

    expect($html)
        ->toContain('data-rg-turnstile')
        ->toContain('data-sitekey="site-key"')
        ->toContain('data-action="'.TurnstileVerifier::ACTION.'"')
        ->toContain('data-field="'.TurnstileVerifier::FIELD.'"')
        ->toContain(e(__('auth.register.javascript_required')))
        ->not->toContain('challenges.cloudflare.com')
        ->not->toContain('secret-key');
})->with(['/register', '/']);

it('draws the widget at the details step of the sign-up inside a game', function () {
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(1);
    liveGame($draft);

    expect(playToResult($draft['game']->slug)->html())
        ->toContain('data-rg-turnstile')
        ->toContain("step === 'details' && \$nextTick");
});

it('draws no widget in a preview of a game, which signs nobody up', function () {
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(1);
    $this->actingAs($draft['admin']);

    Livewire\Livewire::withQueryParams(['start' => 'result:1', 'visitor' => 'anonymous'])
        ->test(PreviewGame::class, ['game' => $draft['game']->id])
        ->assertSee('data-testid="game-signup"', false)
        ->assertDontSee('data-rg-turnstile', false);
});
