<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\MailLocalizationServiceProvider;
use App\Providers\ObservabilityServiceProvider;

return [
    AppServiceProvider::class,
    MailLocalizationServiceProvider::class,
    ObservabilityServiceProvider::class,
    AdminPanelProvider::class,
];
