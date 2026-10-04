<?php

use App\Actions\Moderation\LimitUserAction;
use App\Enums\UserStatus;
use App\Models\User;

it('shows the private restriction notice to each sanctioned living state', function (string $state, string $key) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="account-restriction-notice"', false)
        ->assertSee(__($key));
})->with([
    'limited' => ['limited', 'ui.account_restriction.limited'],
    'banned' => ['banned', 'ui.account_restriction.banned'],
    'shadowbanned' => ['shadowbanned', 'ui.account_restriction.shadowbanned'],
]);

it('shows no notice to active users or guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('data-testid="account-restriction-notice"', false);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('data-testid="account-restriction-notice"', false);
});

it('keeps an existing session working after a ban and surfaces the notice', function () {
    // Real session login (not actingAs, which pins an in-memory instance):
    // the next request re-resolves the user from the database.
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticated();

    // Sanction lands mid-session; the next normal request stays
    // authenticated — living sanctions never force logout — and sees the
    // restriction notice. forgetGuards() models the fresh request: the
    // test app instance otherwise caches the resolved user object.
    User::query()->whereKey($user->id)->update(['status' => UserStatus::Banned]);
    $this->app['auth']->forgetGuards();

    $this->get('/')
        ->assertOk()
        ->assertSee(__('ui.account_restriction.banned'));

    $this->assertAuthenticated();
});

it('never renders the internal moderation reason', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    app(LimitUserAction::class)->handle($admin, $user, 'internal secret reason');

    $this->actingAs($user->fresh())
        ->get('/')
        ->assertOk()
        ->assertSee(__('ui.account_restriction.limited'))
        ->assertDontSee('internal secret reason');
});
