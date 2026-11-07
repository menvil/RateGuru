<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\LanguageRulesServiceProvider;
use App\Providers\MailLocalizationServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\TranslationEngineServiceProvider;

return [
    AppServiceProvider::class,
    LanguageRulesServiceProvider::class,
    MailLocalizationServiceProvider::class,
    ObservabilityServiceProvider::class,
    TranslationEngineServiceProvider::class,
    AdminPanelProvider::class,
];
