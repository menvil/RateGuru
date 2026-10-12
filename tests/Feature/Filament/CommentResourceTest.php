<?php

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Comments in Admin v2: who may open the screen, what each tab lists and how
 * each row reads — the text and where it leads, the author, the post, the
 * reports and the state — with the search read on the server.
 */
it('opens for administrators and moderators, and for nobody else', function (string $role, int $status) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'moderator' => User::factory()->moderator()->create(),
        default => User::factory()->create(),
    };

    $this->actingAs($user)->get(CommentResource::getUrl('index'))->assertStatus($status);
})->with([
    'an administrator' => ['admin', 200],
    'a moderator' => ['moderator', 200],
    'a member' => ['user', 403],
]);

it('sits second in the Moderation section, with the reported comments still visible as its count', function () {
    Comment::factory()->count(2)->create(['reports_count' => 1]);
    Comment::factory()->create(['reports_count' => 3, 'status' => CommentStatus::Hidden]);
    Comment::factory()->create();

    expect(CommentResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION)
        ->and(CommentResource::getNavigationSort())->toBe(20)
        ->and(CommentResource::getNavigationBadge())->toBe('2')
        ->and(CommentResource::getNavigationBadgeColor())->toBe('warning')
        ->and(array_keys(CommentResource::getPages()))->toBe(['index']);
});

it('opens a comment found by global search in the list, by its id', function () {
    $comment = Comment::factory()->create();

    expect(CommentResource::getGlobalSearchResultUrl($comment))->toBe(ListComments::getUrl(['q' => '#'.$comment->id]));
});

// One request: Filament reads the page's scope from the request the page was
// first rendered for.
it('draws the screen in Admin v2', function () {
    $this->actingAs(User::factory()->moderator()->create());
    Comment::factory()->create(['body' => 'Get well soon, Rex']);

    expect($this->get(CommentResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-comments-title" class="rg-admin-page-header__title">Comments</h1>')
        ->toContain('Get well soon, Rex')
        ->not->toContain('fi-ta-table');
});

it('lists each state on its own tab, newest first, and counts every tab', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $visible = Comment::factory()->create(['created_at' => now()->subMinutes(3)]);
    $hidden = Comment::factory()->create(['status' => CommentStatus::Hidden, 'reports_count' => 2, 'created_at' => now()->subMinutes(2)]);
    $deleted = Comment::factory()->create(['created_at' => now()->subMinute()]);
    $deleted->delete();

    $page = Livewire::test(ListComments::class)->assertSet('status', 'all');

    expect(array_column($page->viewData('rows'), 'id'))->toBe([$deleted->id, $hidden->id, $visible->id])
        ->and(array_column($page->viewData('tabs'), 'count', 'id'))->toBe(['all' => '3', 'visible' => '1', 'hidden' => '1', 'deleted' => '1', 'reported' => '1'])
        ->and($page->viewData('stats'))->toBe(['reported' => '1', 'hidden' => '1'])
        ->and(array_column($page->set('status', 'deleted')->viewData('rows'), 'id'))->toBe([$deleted->id])
        ->and(array_column($page->set('status', 'nonsense')->assertSet('status', 'all')->viewData('rows'), 'id'))->toHaveCount(3);
});

it('searches the text, the author, the post and the id on the server', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $rex = Comment::factory()->for(Post::factory()->published()->create(['title' => 'Rex at the vet']))->create(['body' => 'Get well soon']);
    $other = Comment::factory()->create(['body' => 'Lovely ears']);

    $page = Livewire::test(ListComments::class);

    expect(array_column($page->set('q', 'the vet')->viewData('rows'), 'id'))->toBe([$rex->id])
        ->and(array_column($page->set('q', '#'.$other->id)->viewData('rows'), 'id'))->toBe([$other->id]);

    $page->set('q', 'nothing like it')
        ->assertSeeHtml('No comments here')
        ->assertSeeHtml('Nothing matches this state and search.')
        ->call('clearSearch')
        ->assertSet('q', '');
});

it('links a visible comment to its place under a published post, and no other', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $published = Post::factory()->published()->create();
    $linked = Comment::factory()->for($published)->create();
    $onPending = Comment::factory()->for(Post::factory()->pending()->create())->create();
    $hidden = Comment::factory()->for($published)->create(['status' => CommentStatus::Hidden]);

    $page = Livewire::test(ListComments::class);

    expect(listedRow($page, $linked)['thread'])->toBe(route('posts.show', $published).'#comment-'.$linked->id)
        ->and(listedRow($page, $onPending)['thread'])->toBeNull()
        ->and(listedRow($page, $hidden)['thread'])->toBeNull()
        ->and(listedRow($page, $linked)['postUrl'])->toBe(ListPosts::getUrl(['status' => 'all', 'q' => '#'.$published->id]));
});

it('names the author, with their user page for whoever may manage them', function () {
    $this->actingAs(User::factory()->admin()->create());
    $author = User::factory()->create(['username' => 'comment_author']);
    $comment = Comment::factory()->for($author, 'user')->create();

    expect(listedRow(Livewire::test(ListComments::class), $comment)['author'])->toBe([
        'name' => 'comment_author',
        'initials' => 'CO',
        'url' => UserResource::getUrl('edit', ['record' => $author]),
    ]);
});

it('shows each state with its tone and note, and an author-deleted comment read-only, with no actions', function () {
    $this->actingAs(User::factory()->admin()->create());
    $visible = Comment::factory()->create(['reports_count' => 4]);
    $hidden = Comment::factory()->create(['status' => CommentStatus::Hidden]);
    $deleted = Comment::factory()->create();
    $deleted->delete();

    $page = Livewire::test(ListComments::class);

    expect(listedRow($page, $visible))->toMatchArray(['reports' => 4, 'hide' => true, 'restore' => false, 'readOnly' => null])
        ->and(listedRow($page, $visible)['state'])->toBe(['label' => 'Visible', 'tone' => 'success', 'dot' => true, 'note' => null])
        ->and(listedRow($page, $hidden))->toMatchArray(['hide' => false, 'restore' => true])
        ->and(listedRow($page, $hidden)['state'])->toMatchArray(['label' => 'Hidden by moderation', 'tone' => 'neutral', 'note' => 'Restorable'])
        ->and(listedRow($page, $deleted))->toMatchArray(['hide' => false, 'restore' => false, 'readOnly' => 'Read-only · kept for audit', 'finalize' => null])
        ->and(listedRow($page, $deleted)['state']['label'])->toBe('Deleted by author');
});

it('keeps the confirmation on the server', function () {
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(ListComments::class)->set('confirming', ['action' => 'hide', 'id' => 1]);
})->throws(CannotUpdateLockedPropertyException::class);
