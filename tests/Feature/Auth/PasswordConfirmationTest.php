<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
});

test('password confirmation stops answering after six guesses a minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $guess) {
        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password']);
    }

    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password'])->assertStatus(429);
});
