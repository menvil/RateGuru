<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ObservabilityServiceProvider;

return [
    AppServiceProvider::class,
    ObservabilityServiceProvider::class,
    AdminPanelProvider::class,
];
