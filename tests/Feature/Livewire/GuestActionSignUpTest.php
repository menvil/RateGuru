<?php

use App\Enums\VoteType;
use App\Livewire\Comments\CommentVoting;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\AccountBrowser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Livewire;

/**
 * Whether a guest's vote, rating or comment vote opens the sign-in dialog to
 * sign in, as always, or — with the project's switch on, for a browser no
 * account has used — to sign up: the switch lets the owner try which brings
 * more accounts.
 */
const GUEST_ACTION_TOKEN = 'guestActionguestActionguestActionguestAc';

/** The dialog a guest's action opens, from a browser with the given visitor token. */
function guestActionOpens(string $action, ?string $token): mixed
{
    $test = $token === null ? Livewire::test(...) : Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)->test(...);

    return match ($action) {
        'vote' => $test(PostVoting::class, ['postId' => Post::factory()->published()->create()->id])->call('vote', VoteType::Up->value),
        'rating' => (function () use ($test) {
            $post = Post::factory()->published()->create();
            $group = RatingGroup::query()->where('key', 'type')->first() ?? RatingGroup::factory()->create(['key' => 'type']);
            $option = RatingOption::factory()->for($group, 'group')->create();

            return $test(RatingVoting::class, ['post' => $post, 'groupKey' => 'type'])->call('vote', $option->id);
        })(),
        'comment_vote' => $test(CommentVoting::class, ['commentId' => Comment::factory()->for(Post::factory()->published(), 'post')->create()->id])->call('vote', VoteType::Up->value),
    };
}

it('opens sign-in, as always, while the switch is off', function (string $action) {
    ProjectSettings::factory()->create();

    guestActionOpens($action, GUEST_ACTION_TOKEN)->assertDispatched('open-auth-modal', mode: 'login', trigger: $action);
})->with(['vote', 'rating', 'comment_vote']);

it('opens sign-up for a browser no account has used, with the switch on', function (string $action) {
    ProjectSettings::factory()->create(['feature_flags' => ['guest_actions_open_sign_up' => true]]);

    guestActionOpens($action, GUEST_ACTION_TOKEN)->assertDispatched('open-auth-modal', mode: 'register', trigger: $action);
    guestActionOpens($action, null)->assertDispatched('open-auth-modal', mode: 'register', trigger: $action);
})->with(['vote', 'rating', 'comment_vote']);

it('opens sign-in for a browser an account has signed up or signed in with, the switch on or not', function (string $action) {
    ProjectSettings::factory()->create(['feature_flags' => ['guest_actions_open_sign_up' => true]]);
    AccountBrowser::query()->create(['user_id' => User::factory()->create()->id, 'visitor_hash' => browserHashOf(GUEST_ACTION_TOKEN), 'linked_at' => now()]);

    guestActionOpens($action, GUEST_ACTION_TOKEN)->assertDispatched('open-auth-modal', mode: 'login', trigger: $action);
})->with(['vote', 'rating', 'comment_vote']);
