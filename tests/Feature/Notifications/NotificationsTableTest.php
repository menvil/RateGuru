<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has notifications table', function () {
    expect(Schema::hasTable('notifications'))->toBeTrue();

    expect(Schema::hasColumns('notifications', [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('allows user to receive database notification', function () {
    $user = User::factory()->create();

    expect(method_exists($user, 'notify'))->toBeTrue();
    expect(method_exists($user, 'unreadNotifications'))->toBeTrue();
});
