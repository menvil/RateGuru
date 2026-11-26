<?php

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Queries\Posts\AuthorProcessingVideosQuery;

/*
 * An author's video posts still being processed, on their own profile: not
 * out yet, so not among the posts, but not lost from view.
 */

/** A draft whose video is $status. */
function draftWithVideo(User $author, string $title, MediaStatus $status = MediaStatus::Processing, MediaType $type = MediaType::Video): Post
{
    $asset = MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => $type, 'status' => $status]);

    return Post::factory()->for($author)->create(['title' => $title, 'status' => PostStatus::Draft, 'published_at' => null, 'image_asset_id' => $asset->id]);
}

it('shows the owner their videos and GIFs still being processed, marked as such, newest first', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    draftWithVideo($owner, 'Older clip');
    draftWithVideo($owner, 'Newer loop', type: MediaType::Animation);

    $this->actingAs($owner)
        ->get(route('profile.show', ['username' => 'owner']))
        ->assertOk()
        ->assertSee('data-testid="profile-processing-videos"', false)
        ->assertSeeInOrder(['Newer loop', 'Older clip'])
        ->assertSee(__('profile.processing_video'))
        ->assertSee(__('ui.upload.success_video_processing'));
});

it('never shows another member\'s, or a visitor, someone\'s drafts', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    draftWithVideo($owner, 'Private clip');

    $this->actingAs(User::factory()->create())->get(route('profile.show', ['username' => 'owner']))->assertOk()->assertDontSee('Private clip');
    auth()->logout();
    $this->get(route('profile.show', ['username' => 'owner']))->assertOk()->assertDontSee('Private clip');
});

it('lists only drafts still waiting for their video: not one that failed, is ready, or is a photo', function () {
    $owner = User::factory()->create();
    $waiting = draftWithVideo($owner, 'Waiting');
    draftWithVideo($owner, 'Failed', MediaStatus::Failed);
    draftWithVideo($owner, 'Ready', MediaStatus::Ready);
    draftWithVideo($owner, 'Photo', type: MediaType::Image);
    draftWithVideo(User::factory()->create(), 'Someone else\'s');

    expect(app(AuthorProcessingVideosQuery::class)->forAuthor($owner)->pluck('id')->all())->toBe([$waiting->id]);
});
