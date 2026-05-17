<?php

namespace App\Providers;

use App\Services\Images\CloudinaryImageStorage;
use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImageStorage::class, function () {
            return match (config('rateguru.images.driver')) {
                'cloudinary' => $this->app->make(CloudinaryImageStorage::class),
                default => $this->app->make(LocalImageStorage::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
