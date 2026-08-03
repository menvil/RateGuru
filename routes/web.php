<?php

use App\Http\Controllers\Locale\ChangeLocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\SubmitContactMessageController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Feed\FeedPage;
use App\Livewire\Posts\PostShow;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/locale', ChangeLocaleController::class)->name('locale.change')->middleware('throttle:10,1');

Route::get('/', FeedPage::class)->name('feed');

Route::get('/posts/{post}', PostShow::class)->name('posts.show');

Route::get('/u/{username}', ProfilePage::class)->name('profile.show');

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
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/saved', SavedPostsPage::class)->name('saved-posts.index');
});

require __DIR__.'/auth.php';
