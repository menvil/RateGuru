<?php

use App\Enums\SocialProvider;
use App\Http\Controllers\Games\GameResultController;
use App\Http\Controllers\Games\ShareGameResultController;
use App\Http\Controllers\Locale\ChangeLocaleController;
use App\Http\Controllers\Profile\ConnectedAccountController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sharing\SharePostController;
use App\Http\Controllers\SiteManifestController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\SubmitContactMessageController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Http\Middleware\EnsureGamesAreEnabled;
use App\Http\Middleware\RequireSignInWhileGuestViewingIsOff;
use App\Livewire\Feed\FeedPage;
use App\Livewire\Games\PlayGame;
use App\Livewire\Games\PreviewGame;
use App\Livewire\Posts\PostShow;
use App\Livewire\Posts\RecentlyDeletedPostsPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/locale', ChangeLocaleController::class)->name('locale.change')->middleware('throttle:10,1');

// Named from the project's settings, so not a static file in public/.
Route::get('/site.webmanifest', SiteManifestController::class)->name('site-manifest');

Route::middleware(RequireSignInWhileGuestViewingIsOff::class)->group(function () {
    Route::get('/', FeedPage::class)->name('feed');

    Route::get('/posts/{post}', PostShow::class)->name('posts.show');
    Route::get('/posts/{post}/share/{provider}', SharePostController::class)->whereNumber('post')->name('posts.share');

    Route::get('/u/{username}', ProfilePage::class)->name('profile.show');

    // Growth → Games: a live game, a shared result — a profile's, or a
    // player's own — and a share on its way to a provider. None of them
    // exists while Project settings has games off.
    Route::middleware(EnsureGamesAreEnabled::class)->group(function () {
        Route::get('/games/{slug}', PlayGame::class)->where('slug', '[a-z0-9-]+')->name('games.play');
        Route::get('/games/{slug}/v{version}/{profile}', GameResultController::class)
            ->where(['slug' => '[a-z0-9-]+', 'version' => '[0-9]+', 'profile' => '[a-z0-9-]+'])
            ->name('games.result');
        Route::get('/games/{slug}/v{version}/{profile}/share/{provider}', ShareGameResultController::class)
            ->where(['slug' => '[a-z0-9-]+', 'version' => '[0-9]+', 'profile' => '[a-z0-9-]+'])
            ->middleware('throttle:30,1')
            ->name('games.share');
        // A player's own result, by the attempt that earned it: their name on the card.
        Route::get('/games/{slug}/r/{attempt}', [GameResultController::class, 'player'])
            ->where(['slug' => '[a-z0-9-]+', 'attempt' => '[0-9A-Za-z]{26}'])
            ->name('games.player-result');
        Route::get('/games/{slug}/r/{attempt}/share/{provider}', [ShareGameResultController::class, 'player'])
            ->where(['slug' => '[a-z0-9-]+', 'attempt' => '[0-9A-Za-z]{26}'])
            ->middleware('throttle:30,1')
            ->name('games.player-share');
    });
});

// A game as an administrator checks it — a draft or a published version —
// recording nothing. Not behind the games switch: games are prepared before
// visitors may reach them.
Route::get('/games/{game}/preview', PreviewGame::class)
    ->whereNumber('game')
    ->middleware(['auth', 'can:manage-games'])
    ->name('games.preview');

Route::get('/about', [StaticPageController::class, 'about'])->name('pages.about');
Route::get('/privacy', [StaticPageController::class, 'privacy'])->name('pages.privacy');
Route::get('/terms', [StaticPageController::class, 'terms'])->name('pages.terms');
Route::get('/contact', [StaticPageController::class, 'contact'])->name('pages.contact');
Route::post('/contact', SubmitContactMessageController::class)
    ->middleware('throttle:5,1')
    ->name('pages.contact.submit');

Route::get('/dashboard', function () {
    return redirect()->route('feed');
})->name('dashboard');

Route::get('/dev/ui-kit', function () {
    $demoPost = new Post([
        'title' => 'Sample Post',
        'description' => 'A neutral preview used to demonstrate configurable rating components.',
        'upvotes_count' => 128,
        'downvotes_count' => 12,
        'comments_count' => 24,
    ]);
    $demoPost->setRelation('user', new User([
        'name' => 'Demo Author',
        'username' => 'demo_author',
    ]));

    $demoRatingGroup = new RatingGroup([
        'key' => 'example',
        'label' => 'Configurable rating',
    ]);
    $demoRatingGroup->setRelation('options', collect([
        new RatingOption(['id' => 1, 'label' => 'Option A']),
        new RatingOption(['id' => 2, 'label' => 'Option B']),
        new RatingOption(['id' => 3, 'label' => 'Option C']),
    ]));

    return view('dev.ui-kit', compact('demoPost', 'demoRatingGroup'));
})->middleware(EnsureDevEnvironment::class)->name('dev.ui-kit');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Limited, as it checks the current password when the email changes.
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:6,1,profile-update')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/profile/connected-accounts/{provider}', [ConnectedAccountController::class, 'store'])
        ->whereIn('provider', SocialProvider::values())
        ->middleware('throttle:10,1')
        ->name('profile.connected-accounts.store');
    Route::delete('/profile/connected-accounts/{provider}', [ConnectedAccountController::class, 'destroy'])
        ->whereIn('provider', SocialProvider::values())
        ->middleware('throttle:10,1')
        ->name('profile.connected-accounts.destroy');

    Route::get('/saved', SavedPostsPage::class)->name('saved-posts.index');

    Route::get('/account/posts/deleted', RecentlyDeletedPostsPage::class)->name('posts.recently-deleted');
});

require __DIR__.'/auth.php';
