<?php

use App\Enums\SocialProvider;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use App\Notifications\SocialAccountConnectedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;

/*
 * A Persona's address is a synthetic identifier on a reserved domain: the
 * mail channel never delivers to it, whatever the notification. Only mail
 * is closed — a Persona's inbox fills like anyone's, because it takes part
 * in the community like anyone.
 */

/** @return int how many messages the array mailer has delivered so far */
function deliveredMailCount(): int
{
    return Mail::mailer()->getSymfonyTransport()->messages()->count();
}

it('delivers no mail to a persona, and the same mail to a member', function (Closure $notification) {
    $persona = User::factory()->persona()->create();
    $member = User::factory()->create();
    $before = deliveredMailCount();

    $persona->notify($notification());

    expect(deliveredMailCount())->toBe($before);

    $member->notify($notification());

    expect(deliveredMailCount())->toBe($before + 1);
})->with([
    'a security notice' => fn () => new SocialAccountConnectedNotification(SocialProvider::Google, null),
    'a password reset link' => fn () => new ResetPassword('a-token'),
]);

it('still writes a persona\'s database notifications', function () {
    $persona = User::factory()->persona()->create();
    $commenter = User::factory()->create();
    $post = Post::factory()->for($persona)->published()->create();
    $comment = Comment::factory()->for($post)->for($commenter, 'user')->create();

    $persona->notify(new PostCommentedNotification($post, $comment, $commenter));

    expect($persona->notifications()->count())->toBe(1)
        ->and($persona->notifications()->sole()->type)->toBe(PostCommentedNotification::class);
});
