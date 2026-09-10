<?php

use App\Models\User;
use App\Support\Notifications\NotificationMessage;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * In-app notifications are read in the reader's language, at read time.
 *
 * They used to store a rendered English sentence, which is unfixable after the
 * fact: the row outlives the moment it was written, and the reader may not have
 * chosen their language yet — or may have changed it since. So the payload now
 * carries a key and its parameters, and the sentence is produced when the bell
 * is drawn.
 */

/** @param array<string, mixed> $data */
function notificationWith(array $data): DatabaseNotification
{
    return tap(new DatabaseNotification, fn (DatabaseNotification $n) => $n->forceFill(['data' => $data]));
}

it('renders a stored key in the reader language', function (string $locale, string $expected) {
    app()->setLocale($locale);

    $message = NotificationMessage::for(notificationWith([
        'type' => 'post_commented',
        'message_key' => 'ui.notifications.messages.post_commented',
        'message_params' => ['username' => 'kate'],
    ]));

    expect($message)->toBe($expected);
})->with([
    ['en', '@kate commented on your post'],
    ['ru', '@kate прокомментировал ваш пост'],
    ['bg', '@kate коментира вашата публикация'],
]);

it('substitutes every parameter a message declares', function () {
    app()->setLocale('ru');

    expect(NotificationMessage::for(notificationWith([
        'message_key' => 'ui.notifications.messages.followed_author_posted',
        'message_params' => ['username' => 'kate', 'title' => 'Закат'],
    ])))->toBe('@kate опубликовал Закат')
        // A dropped :placeholder in a translation is the classic way this
        // breaks, and it shows up as the raw token surviving the render.
        ->and(NotificationMessage::for(notificationWith([
            'message_key' => 'ui.notifications.messages.followed_author_posted',
            'message_params' => ['username' => 'kate', 'title' => 'Закат'],
        ])))->not->toContain(':');
});

it('still renders rows written before the switch', function () {
    // Those rows keep the English they were stored with. That is not a
    // regression — it is exactly what they have always shown — and it is
    // strictly better than guessing a key by matching prose.
    app()->setLocale('ru');

    expect(NotificationMessage::for(notificationWith([
        'type' => 'post_approved',
        'message' => 'Your post was approved',
    ])))->toBe('Your post was approved');
});

it('prefers the key when a row somehow carries both', function () {
    app()->setLocale('ru');

    expect(NotificationMessage::for(notificationWith([
        'message_key' => 'ui.notifications.messages.post_approved',
        'message_params' => [],
        'message' => 'Your post was approved',
    ])))->toBe('Ваш пост одобрен');
});

it('falls back for a payload carrying neither', function () {
    app()->setLocale('bg');

    expect(NotificationMessage::for(notificationWith(['type' => 'something_new'])))
        ->toBe(__('ui.notifications.fallback_message'));
});

it('survives a malformed parameter bag', function () {
    app()->setLocale('en');

    expect(NotificationMessage::for(notificationWith([
        'message_key' => 'ui.notifications.messages.post_approved',
        'message_params' => 'not-an-array',
    ])))->toBe('Your post was approved');
});

// ---------------------------------------------------------------------------
// The backfill
// ---------------------------------------------------------------------------

/** @param array<string, mixed> $data */
function insertLegacyNotification(string $id, array $data): void
{
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Legacy',
        'notifiable_type' => User::class,
        'notifiable_id' => User::factory()->create()->id,
        'data' => json_encode($data),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function runBackfill(): void
{
    (require base_path('database/migrations/2026_09_09_090000_backfill_notification_message_keys.php'))->up();
}

/** @return array<string, mixed> */
function notificationData(string $id): array
{
    return json_decode((string) DB::table('notifications')->where('id', $id)->value('data'), true);
}

it('rebuilds keys from structured fields, not from English prose', function () {
    insertLegacyNotification('11111111-1111-1111-1111-111111111111', [
        'type' => 'followed_author_posted',
        'post_id' => 7,
        'post_title' => 'Sunset',
        'author_username' => 'kate',
        'message' => '@kate posted Sunset',
    ]);

    runBackfill();

    $data = notificationData('11111111-1111-1111-1111-111111111111');

    expect($data['message_key'])->toBe('ui.notifications.messages.followed_author_posted')
        ->and($data['message_params'])->toBe(['username' => 'kate', 'title' => 'Sunset'])
        // The old sentence stays: if this row were somehow wrong, the reader
        // still sees what they always saw rather than a blank.
        ->and($data['message'])->toBe('@kate posted Sunset');

    app()->setLocale('ru');
    expect(NotificationMessage::for(notificationWith($data)))->toBe('@kate опубликовал Sunset');
});

it('is idempotent', function () {
    insertLegacyNotification('22222222-2222-2222-2222-222222222222', [
        'type' => 'post_approved',
        'message' => 'Your post was approved',
    ]);

    runBackfill();
    $first = notificationData('22222222-2222-2222-2222-222222222222');

    runBackfill();

    expect(notificationData('22222222-2222-2222-2222-222222222222'))->toBe($first);
});

it('leaves a type it does not know alone', function () {
    insertLegacyNotification('33333333-3333-3333-3333-333333333333', [
        'type' => 'invented_later',
        'message' => 'Something happened',
    ]);

    runBackfill();

    expect(notificationData('33333333-3333-3333-3333-333333333333'))
        ->not->toHaveKey('message_key')
        ->and(notificationData('33333333-3333-3333-3333-333333333333')['message'])->toBe('Something happened');
});

it('leaves a row missing the field its sentence was built from alone', function () {
    // Rebuilding "@:username commented" without a username would produce a
    // sentence with a hole in it; the old text is better than that.
    insertLegacyNotification('44444444-4444-4444-4444-444444444444', [
        'type' => 'post_commented',
        'message' => '@ghost commented on your post',
    ]);

    runBackfill();

    expect(notificationData('44444444-4444-4444-4444-444444444444'))->not->toHaveKey('message_key');
});
