<?php

use App\Livewire\Profile\EditProfileForm;
use App\Models\User;
use App\Rules\ContainsNoLink;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

function nameIsAccepted(mixed $name, ?string $current = null): bool
{
    return Validator::make(['name' => $name], ['name' => [new ContainsNoLink($current)]])->passes();
}

it('refuses a name carrying a link', function (string $name) {
    expect(nameIsAccepted($name))->toBeFalse();
})->with([
    'scheme' => 'https://spam.example',
    'scheme in a sentence' => 'Visit http://spam.example now',
    'other scheme' => 'ftp://spam.example',
    'www host' => 'www.spam.example',
    'www host after a space' => 'Visit www.spam.example',
    'uppercase www' => 'WWW.spam.example',
    'markdown link' => '[Claim your prize](https://spam.example)',
    'markdown link without a scheme' => '[Claim](spam.example)',
    'markdown link with a space' => '[Claim] (spam.example)',
    'full-width scheme' => 'https：／／spam.example',
    'full-width www' => 'ｗｗｗ．spam.example',
]);

it('accepts an ordinary name', function (string $name) {
    expect(nameIsAccepted($name))->toBeTrue();
})->with([
    'plain' => 'Ivan Moroz',
    'initials' => 'J.R. Smith',
    'www at the end of a word' => 'Awww.',
    'apostrophe' => "Conan O'Brien",
    'cyrillic' => 'Иван Мороз',
    'brackets without a link' => 'Ivan [admin]',
    'parentheses' => 'Ivan (Kyiv)',
]);

it('refuses text that is not valid UTF-8', function () {
    expect(nameIsAccepted("Ivan\xFF"))->toBeFalse();
});

it('lets an account keep the name it already has', function () {
    expect(nameIsAccepted('www.old-name.example', 'www.old-name.example'))->toBeTrue()
        ->and(nameIsAccepted('www.new-name.example', 'www.old-name.example'))->toBeFalse();
});

it('refuses a link in the name at registration', function () {
    $this->post('/register', [
        'name' => '[Claim your prize](https://spam.example)',
        'email' => 'victim@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('name');

    $this->assertGuest();
    expect(User::query()->where('email', 'victim@example.com')->exists())->toBeFalse();
});

it('refuses a new name with a link on the profile, and keeps an old one', function () {
    $user = User::factory()->create(['name' => 'www.old-name.example', 'username' => 'kept_user']);
    $fields = ['username' => 'kept_user', 'email' => $user->email];

    $this->actingAs($user)->patch('/profile', [...$fields, 'name' => 'www.old-name.example'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->patch('/profile', [...$fields, 'name' => 'https://spam.example'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->name)->toBe('www.old-name.example');
});

it('refuses a new display name with a link, and keeps an old one', function () {
    $user = User::factory()->create(['display_name' => 'www.old-name.example']);

    Livewire::actingAs($user)->test(EditProfileForm::class)
        ->call('save')
        ->assertHasNoErrors('display_name')
        ->set('display_name', 'https://spam.example')
        ->call('save')
        ->assertHasErrors('display_name');

    expect($user->fresh()->display_name)->toBe('www.old-name.example');
});
