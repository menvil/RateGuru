<?php

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureDevEnvironment;
use Illuminate\Support\Facades\Route;

Route::view('/', 'feed.placeholder')->name('feed');

Route::get('/dashboard', function () {
    return redirect()->route('feed');
})->name('dashboard');

Route::view('/dev/ui-kit', 'dev.ui-kit')
    ->middleware(EnsureDevEnvironment::class)
    ->name('dev.ui-kit');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
