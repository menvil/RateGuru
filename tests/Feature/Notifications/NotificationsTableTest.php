<?php

use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;
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

    $user->notify(new NotificationsTableDatabaseNotification());

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $user->getKey(),
        'notifiable_type' => get_class($user),
        'type' => NotificationsTableDatabaseNotification::class,
    ]);
});

final class NotificationsTableDatabaseNotification extends BaseNotification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['message' => 'Database notification smoke test'];
    }
}
