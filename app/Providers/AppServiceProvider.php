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
            $driver = config('rateguru.images.driver');

            return match ($driver) {
                'local'      => $this->app->make(LocalImageStorage::class),
                'cloudinary' => $this->app->make(CloudinaryImageStorage::class),
                default      => throw new \InvalidArgumentException("Unsupported image driver: [{$driver}]."),
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
