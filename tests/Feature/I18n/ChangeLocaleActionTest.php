<?php

use App\Models\User;

it('changes locale for guest by storing it in session', function () {
    $this->post(route('locale.change'), ['locale' => 'ru'])
        ->assertRedirect();

    expect(session('locale'))->toBe('ru');
});

it('changes locale preference for authenticated user', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.change'), ['locale' => 'bg'])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('bg');
});

it('also stores locale in session for authenticated user', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.change'), ['locale' => 'ru'])
        ->assertRedirect();

    expect(session('locale'))->toBe('ru');
});

it('rejects unsupported locale change', function () {
    $this->post(route('locale.change'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale');
});
