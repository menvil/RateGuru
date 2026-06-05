<?php

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Feed\FeedPage;
use App\Livewire\Posts\PostShow;
use App\Livewire\Profile\ProfilePage;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', FeedPage::class)->name('feed');

Route::get('/posts/{post}', PostShow::class)->name('posts.show');

Route::get('/u/{username}', ProfilePage::class)->name('profile.show');

Route::get('/dashboard', function () {
    return redirect()->route('feed');
})->name('dashboard');

Route::get('/dev/ui-kit', function () {
    $demoPost = new Post([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper and guanciale.',
        'upvotes_count' => 128,
        'downvotes_count' => 12,
        'comments_count' => 24,
        'homemade_votes_count' => 70,
        'restaurant_votes_count' => 30,
        'image_url' => null,
    ]);
    $demoPost->setRelation('user', new User([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
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
});

require __DIR__.'/auth.php';
