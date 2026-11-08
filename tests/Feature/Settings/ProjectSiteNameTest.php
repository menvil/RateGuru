<?php

use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\ProjectSettings;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountConnectedNotification;
use App\Notifications\SocialAccountDisconnectedNotification;
use App\Support\Notifications\MailAddressee;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\StudioAi\OpenAiStudioDiscussionGenerator;
use App\Support\StudioAi\OpenAiStudioPostTextGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/*
 * A project is called what its settings call it, wherever the application
 * names it: the admin, the emails, the account pages. RateGuru is only the
 * name a project starts with before it has one of its own, and APP_NAME is
 * the deployment's, never shown to anyone.
 */

beforeEach(function () {
    config(['app.name' => 'DeploymentName']);
    ProjectSettings::factory()->create(['site_name' => 'Pawrank']);
    app(ProjectSettingsManager::class)->flush();
});

it('brands the admin with the project name', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->getContent();

    expect($html)
        ->toContain('<span class="rg-admin-workspace__name">Pawrank</span>')
        ->toContain('<span class="rg-admin-workspace__mark" aria-hidden="true">P</span>')
        ->toMatch('/<title>[^<]*Pawrank[^<]*<\/title>/')
        ->not->toContain('RateGuru')
        ->not->toContain('DeploymentName');
});

it('brands the admin sign-in page with the project name', function () {
    expect($this->get('/admin/login')->assertOk()->getContent())
        ->toContain('Pawrank')
        ->not->toContain('RateGuru')
        ->not->toContain('DeploymentName');
});

it('signs the emails Laravel sends with the project name', function (object $notification) {
    $body = renderedMail($notification, User::factory()->unverified()->create(['name' => 'Reader']))['body'];

    expect($body)
        ->toContain('Regards, Pawrank')
        ->toContain('© '.date('Y').' Pawrank.')
        ->not->toContain('RateGuru')
        ->not->toContain('DeploymentName');
})->with([
    'verification' => fn () => new VerifyEmail,
    'password reset' => fn () => new ResetPassword('a-token'),
]);

it('signs an email that sets no salutation with the project name', function () {
    $body = (string) (new MailMessage)->line('A line.')->render();

    expect($body)
        ->toContain('Regards, Pawrank')
        ->not->toContain('DeploymentName');
});

it('names the project in the connected-account security emails', function (string $notification, string $line) {
    $body = renderedMail(new $notification(SocialProvider::Google, null), User::factory()->create())['body'];

    expect($body)
        ->toContain($line)
        ->toContain('Regards, Pawrank')
        ->not->toContain('RateGuru')
        ->not->toContain('DeploymentName');
})->with([
    'connected' => [SocialAccountConnectedNotification::class, 'A Google account was connected to your Pawrank account.'],
    'disconnected' => [SocialAccountDisconnectedNotification::class, 'A Google account was disconnected from your Pawrank account.'],
]);

it('greets a recipient with no name of its own by the project name', function () {
    expect(MailAddressee::nameOf(new AnonymousNotifiable))->toBe('Pawrank')
        ->and(MailAddressee::nameOf(User::factory()->make(['name' => ' ', 'display_name' => null])))->toBe('Pawrank');
});

it('names the project when a provider email belongs to another account', function () {
    expect(SocialAuthenticationException::emailBelongsToAnotherAccount(SocialProvider::Google)->userMessage())
        ->toBe('The email address of this Google account belongs to another Pawrank account. Sign in to that account to connect Google there.');
});

it('names the project in the connected accounts of the profile', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('A connected account may use a different email address than your Pawrank account.')
        ->assertDontSee('RateGuru');
});

it('ships no catalog string or page text that names the generic brand', function () {
    $named = collect(Finder::create()->files()->in(lang_path())->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'RateGuru'))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($named)->toBe([])
        ->and(json_encode(config('static-pages.defaults')))->not->toContain('RateGuru');
});

it('asks Content Studio for texts that name no site', function (string $generator) {
    expect((new ReflectionClassConstant($generator, 'INSTRUCTIONS'))->getValue())->not->toContain('RateGuru');
})->with([
    OpenAiStudioPostTextGenerator::class,
    OpenAiStudioDiscussionGenerator::class,
]);
