<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

it('renders feed interface in Russian locale', function () {
    $this->withSession(['locale' => 'ru'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Популярные')
        ->assertSee('Войти');
});

it('renders feed interface in Bulgarian locale', function () {
    $this->withSession(['locale' => 'bg'])
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('Популярни')
        ->assertSee('Вход');
});

it('renders feed interface in English by default', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('Hot')
        ->assertSee('Log in');
});

it('renders the sign-in screen in the visitor language through named keys', function (string $locale) {
    $this->withSession(['locale' => $locale])
        ->get(route('login'))
        ->assertOk()
        ->assertSee(__('auth.fields.email', [], $locale))
        ->assertSee(__('auth.fields.password', [], $locale))
        ->assertSee(__('auth.login.remember', [], $locale))
        ->assertSee(__('auth.login.forgot_password', [], $locale))
        ->assertSee(__('auth.login.action', [], $locale))
        // The English these screens used to carry as JSON prose keys.
        ->assertDontSee('Remember me')
        ->assertDontSee('Forgot your password?');
})->with(translatedLocales());

it('renders the registration and password screens in the visitor language', function (string $locale) {
    $this->withSession(['locale' => $locale])
        ->get(route('register'))
        ->assertOk()
        ->assertSee(__('auth.fields.password_confirmation', [], $locale))
        ->assertSee(__('auth.register.action', [], $locale))
        ->assertDontSee('Confirm Password');

    $this->withSession(['locale' => $locale])
        ->get(route('password.request'))
        ->assertOk()
        ->assertSee(__('auth.forgot_password.intro', [], $locale))
        ->assertSee(__('auth.forgot_password.action', [], $locale))
        ->assertDontSee('Email Password Reset Link');
})->with(translatedLocales());

it('renders account settings in the account language', function (string $locale) {
    $user = User::factory()->create(['locale' => $locale]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(__('profile.information.title', [], $locale))
        ->assertSee(__('profile.password.update_title', [], $locale))
        ->assertSee(__('profile.delete.title', [], $locale))
        ->assertSee(__('profile.delete.confirm_title', [], $locale))
        ->assertSee(__('profile.delete.action', [], $locale))
        ->assertDontSee('Delete Account')
        ->assertDontSee('Profile Information')
        ->assertDontSee('Update Password');
})->with(translatedLocales());

it('names the failing field in the visitor language', function (string $locale) {
    $this->withSession(['locale' => $locale])
        ->from(route('register'))
        ->post(route('register'), ['name' => 'Reader', 'email' => '', 'password' => 'long-enough-password', 'password_confirmation' => 'long-enough-password'])
        ->assertSessionHasErrors([
            'email' => __('validation.required', ['attribute' => __('validation.attributes.email', [], $locale)], $locale),
        ]);
})->with(supportedLocales());

it('reports a password reset link in the visitor language', function (string $locale) {
    Notification::fake();
    User::factory()->create(['email' => "reader-{$locale}@example.test"]);

    $this->withSession(['locale' => $locale])
        ->from(route('password.request'))
        ->post(route('password.email'), ['email' => "reader-{$locale}@example.test"])
        ->assertSessionHas('status', __('passwords.sent', [], $locale));
})->with(supportedLocales());
