<?php

use App\Livewire\Notifications\NotificationBell;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;

it('can render notification bell for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertStatus(200)
        ->assertSee('data-testid="notification-bell"', false);
});

it('does not render notification bell for guest', function () {
    Livewire::test(NotificationBell::class)
        ->assertDontSee('data-testid="notification-bell"', false);
});

it('shows unread notification count for authenticated user', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification(['message' => 'Unread 1']));
    $user->notify(new TestDatabaseNotification(['message' => 'Unread 2']));

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notification-unread-count"', false)
        ->assertSee('2');
});

it('does not count read notifications', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification(['message' => 'Unread']));
    $user->notify(new TestDatabaseNotification(['message' => 'Read']));

    $user->notifications()->latest()->first()->markAsRead();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notification-unread-count"', false)
        ->assertSee('1');
});

it('does not count other users notifications', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->notify(new TestDatabaseNotification(['message' => 'Own unread']));
    $otherUser->notify(new TestDatabaseNotification(['message' => 'Other unread']));

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notification-unread-count"', false)
        ->assertSee('1');
});

it('hides unread count when user has no unread notifications', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertDontSee('data-testid="notification-unread-count"', false);
});

it('renders notifications dropdown with notification messages', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification([
        'message' => 'Your post was approved',
        'url' => '/posts/1',
    ]));

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notifications-dropdown"', false)
        ->assertSee('Your post was approved');
});

it('renders notifications empty state', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('No notifications yet');
});

it('renders only latest notifications in dropdown', function () {
    $user = User::factory()->create();

    foreach (range(1, 11) as $index) {
        $user->notify(new TestDatabaseNotification([
            'message' => $index === 1 ? 'Old notification' : 'Notification '.$index,
            'url' => '/posts/'.$index,
        ]));
    }

    $user->notifications()->get()->each(function ($notification): void {
        $index = $notification->data['message'] === 'Old notification'
            ? 1
            : (int) str_replace('Notification ', '', $notification->data['message']);

        $notification->forceFill(['created_at' => now()->addSeconds($index)])->save();
    });

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('Notification 11')
        ->assertDontSee('Old notification');
});

final class TestDatabaseNotification extends Notification
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
