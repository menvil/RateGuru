<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

/*
 * users:purge-unconfirmed removes, every day, the accounts that did not
 * confirm their email address within a week of signing up
 * (docs/architecture/user-lifecycle.md, "Unconfirmed accounts"); --dry-run
 * lists them by name instead.
 */

it('lists who would go on a dry run and removes nobody', function () {
    $bot = User::factory()->unverified()->create(['username' => 'spam_bot', 'created_at' => now()->subDays(8)]);
    $recent = User::factory()->unverified()->create(['created_at' => now()->subDays(2)]);

    $this->artisan('users:purge-unconfirmed', ['--dry-run' => true])
        ->expectsOutputToContain("user {$bot->id} (spam_bot, {$bot->email}")
        ->expectsOutputToContain('would_purge: 1')
        ->assertSuccessful();

    expect($bot->fresh()->anonymized_at)->toBeNull()
        ->and($recent->fresh()->anonymized_at)->toBeNull();
});

it('removes the accounts a week past signing up unconfirmed, and only those', function () {
    $bot = User::factory()->unverified()->create(['created_at' => now()->subDays(8)]);
    $post = Post::factory()->published()->for($bot)->create();
    $recent = User::factory()->unverified()->create(['created_at' => now()->subDays(2)]);
    $member = User::factory()->create(['created_at' => now()->subDays(30)]);

    $this->artisan('users:purge-unconfirmed')
        ->expectsOutputToContain('purged: 1')
        ->doesntExpectOutputToContain($bot->email)
        ->assertSuccessful();

    expect($bot->fresh()->anonymized_at)->not->toBeNull()
        ->and(Post::withTrashed()->find($post->id))->toBeNull()
        ->and($recent->fresh()->anonymized_at)->toBeNull()
        ->and($member->fresh()->anonymized_at)->toBeNull();

    $this->artisan('users:purge-unconfirmed')
        ->expectsOutputToContain('No unconfirmed accounts to remove.')
        ->assertSuccessful();
});

it('answers for one account with --user, whatever it is', function () {
    $member = User::factory()->create();

    $this->artisan('users:purge-unconfirmed', ['--user' => (string) $member->id])
        ->expectsOutputToContain("user {$member->id}: not_eligible")
        ->assertSuccessful();

    $this->artisan('users:purge-unconfirmed', ['--user' => 'nobody'])
        ->expectsOutputToContain('The --user option must be a positive integer id.')
        ->assertFailed();
});

it('runs every day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'users:purge-unconfirmed'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
