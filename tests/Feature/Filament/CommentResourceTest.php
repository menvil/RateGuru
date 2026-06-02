<?php

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access comment resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access comment resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(CommentResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access comment resource index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(CommentResource::getUrl('index'))
        ->assertForbidden();
});

it('uses the Comment model', function () {
    expect(CommentResource::getModel())->toBe(Comment::class);
});

it('renders in the flat admin navigation', function () {
    expect(CommentResource::getNavigationGroup())->toBeNull();
});

it('does not expose create or edit pages in this phase', function () {
    expect(array_keys(CommentResource::getPages()))->toBe(['index']);
});

it('renders comment body excerpt in comment resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create([
        'body' => 'This comment should be visible as an excerpt in the admin table.',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$comment])
        ->assertTableColumnExists('body')
        ->assertCanRenderTableColumn('body')
        ->assertSee('This comment should be visible')
        ->assertSee(route('posts.show', $comment->post).'#comment-'.$comment->id, false);
});

it('renders comment author in comment resource table', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['username' => 'comment_author']);
    $comment = Comment::factory()->for($author, 'user')->create();

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$comment])
        ->assertTableColumnExists('user.username')
        ->assertCanRenderTableColumn('user.username')
        ->assertSee('comment_author');
});

it('renders related post in comment resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create(['title' => 'Pasta post']);
    $comment = Comment::factory()->for($post)->create();

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$comment])
        ->assertTableColumnExists('post.title')
        ->assertCanRenderTableColumn('post.title')
        ->assertSee('Pasta post')
        ->assertSee(route('posts.show', $post), false);
});

it('does not link comments to public pages for non-published posts', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create(['title' => 'Pending post']);
    $comment = Comment::factory()->for($post)->create([
        'body' => 'Comment on pending post',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$comment])
        ->assertSee('Comment on pending post')
        ->assertSee('Pending post')
        ->assertDontSee(route('posts.show', $post), false)
        ->assertDontSee(route('posts.show', $post).'#comment-'.$comment->id, false);
});

it('renders sortable status badge column in comment resource table', function () {
    $admin = User::factory()->admin()->create();
    $hidden = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$hidden])
        ->assertTableColumnExists('status')
        ->assertCanRenderTableColumn('status')
        ->assertSee('hidden');
});

it('renders comment reports count in comment resource table', function () {
    $admin = User::factory()->admin()->create();
    $reported = Comment::factory()->create([
        'body' => 'Reported comment',
        'reports_count' => 4,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertCanSeeTableRecords([$reported])
        ->assertTableColumnExists('reports_count')
        ->assertCanRenderTableColumn('reports_count')
        ->assertTableColumnStateSet('reports_count', 4, $reported);
});
