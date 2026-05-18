<?php

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Feed\FeedPage;
use Illuminate\Support\Facades\Route;

Route::get('/', FeedPage::class)->name('feed');

// RG-242: posts.show route is registered with a temporary placeholder and
// will be connected to the PostShow Livewire component in RG-243.
Route::get('/posts/{post}', function (\App\Models\Post $post) {
    abort_unless($post->status === \App\Enums\PostStatus::Published, 404);

    return $post->title;
})->name('posts.show');

Route::get('/dashboard', function () {
    return redirect()->route('feed');
})->name('dashboard');

Route::get('/dev/ui-kit', function () {
    $demoPost = new \App\Models\Post([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper and guanciale.',
        'upvotes_count' => 128,
        'downvotes_count' => 12,
        'comments_count' => 24,
        'homemade_votes_count' => 70,
        'restaurant_votes_count' => 30,
        'image_url' => null,
    ]);
    $demoPost->setRelation('user', new \App\Models\User([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]));

    return view('dev.ui-kit', compact('demoPost'));
})->middleware(EnsureDevEnvironment::class)->name('dev.ui-kit');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
