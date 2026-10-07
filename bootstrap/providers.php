<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\MailLocalizationServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\TranslationEngineServiceProvider;

return [
    AppServiceProvider::class,
    MailLocalizationServiceProvider::class,
    ObservabilityServiceProvider::class,
    TranslationEngineServiceProvider::class,
    AdminPanelProvider::class,
];
