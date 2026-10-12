<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\Browser\Support\ImageFixtures;

use function Pest\Laravel\actingAs;

/*
 * A post's life from its author's side, walked through the pages a person
 * uses rather than component by component: signing up and posting, and taking
 * a post down and bringing it back.
 */

afterEach(function () {
    ImageFixtures::cleanup();
});

it('holds a newcomer\'s post for a moderator until they confirm their email, then publishes the next one straight into the feed', function () {
    // Stored where the browser test server serves files from, so the picture
    // the application makes of the upload actually loads in the feed.
    config(['media.disks.public' => ImageFixtures::disk()]);

    // The server fetches each picture through the import pipeline; only the
    // network under it is scripted. The address is in the reserved .test
    // zone, so the browser's own preview of it never reaches anyone.
    bindFakeHostResolver(['images.rateguru.test' => ['93.184.216.34']]);
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(640, 480)),
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(640, 480)),
    ]));

    // See the registration test in AuthModalBrowserTest: the in-process
    // server hands the next request the very instance registration created.
    Event::listen(Registered::class, fn (Registered $event) => $event->user->refresh());

    $page = visit(route('feed'))
        ->click('@guest-upload-button')
        ->type('@auth-modal-register-name', 'Nadia Newcomer')
        ->type('@auth-modal-register-email', 'nadia@rateguru.test')
        ->type('@auth-modal-register-password', 'password')
        ->type('@auth-modal-register-password-confirmation', 'password');

    // Until the address is confirmed, a post waits for a moderator, and its
    // author is told so.
    submitAndWaitForNewPage($page, '@auth-modal-register-submit')
        ->assertPresent('@header-auth-actions')
        ->click('@open-upload-button')
        ->click('@image-tab-url')
        ->type('@upload-image-url-input', 'https://images.rateguru.test/first-dish.jpg')
        ->type('#title', 'My very first dish')
        ->click('[data-testid="upload-form"] button[type="submit"]')
        ->assertMissing('@upload-modal')
        ->assertSeeIn('@toast-container', 'will appear after moderation')
        ->assertDontSee('My very first dish');

    $newcomer = User::query()->where('email', 'nadia@rateguru.test')->sole();

    expect(Post::query()->where('title', 'My very first dish')->sole()->status)->toBe(PostStatus::Pending);

    // The link from the confirmation email, opened in the same browser, where
    // the newcomer is signed in. Once it is, the next post goes straight into
    // the feed.
    $page->navigate(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $newcomer->id,
        'hash' => sha1($newcomer->getEmailForVerification()),
    ]));

    expect($newcomer->fresh()->hasVerifiedEmail())->toBeTrue();

    $page->navigate(route('feed'))
        ->click('@open-upload-button')
        ->click('@image-tab-url')
        ->type('@upload-image-url-input', 'https://images.rateguru.test/second-dish.jpg')
        ->type('#title', 'My second dish')
        ->click('[data-testid="upload-form"] button[type="submit"]')
        ->assertMissing('@upload-modal')
        ->assertSeeIn('@toast-container', 'Your post has been published.')
        ->assertSeeIn('@post-card-title', 'My second dish');

    // The picture went through import, normalisation and storage, and the
    // card shows what came out.
    waitForScript($page, '(() => { const img = document.querySelector(\'[data-testid="post-card"] img\'); return img !== null && img.complete && img.naturalWidth > 0; })()');

    $post = Post::query()->where('title', 'My second dish')->sole();

    expect($post->user->is($newcomer))->toBeTrue()
        ->and($post->status)->toBe(PostStatus::Published);

    // Its author can open it, but not vote for it.
    $page->navigate(route('posts.show', $post))
        ->assertSee('My second dish')
        ->assertAttribute("@post-upvote-button-{$post->id}", 'disabled', '');
});

it('lets an author delete a post from its card and bring it back from recently deleted', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create(['title' => 'A post worth keeping']);

    actingAs($author);

    $page = visit(route('feed'))
        ->click("@post-actions-menu-{$post->id}")
        ->click('@post-card-delete')
        ->click('[role="dialog"] button[wire\:click*="delete-post"]')
        ->assertDontSee('A post worth keeping');

    $page->click('@header-user-menu-trigger')
        ->click('@nav-recently-deleted')
        ->assertPathIs(route('posts.recently-deleted', absolute: false))
        ->assertSeeIn('@recently-deleted-item', 'A post worth keeping')
        ->click("@restore-post-{$post->id}")
        ->assertSeeIn('@recently-deleted-status', '"A post worth keeping" has been restored.')
        ->assertMissing('@recently-deleted-item');

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    visit(route('feed'))->assertSeeIn('@post-card-title', 'A post worth keeping');
    visit(route('profile.show', $author->username))->assertSee('A post worth keeping');
});
