<?php

namespace App\Providers;

use App\Support\Locale\LanguageRules;
use App\Support\Locale\PluralSelector;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;

/**
 * Tells Laravel's translator and Carbon what LanguageRules knows about the
 * installed languages their tables miss.
 */
final class LanguageRulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('translator', function (Translator $translator): Translator {
            $translator->setSelector(new PluralSelector);

            return $translator;
        });
    }

    public function boot(): void
    {
        // Runs after Carbon's own listener, which has just set the plain code.
        Event::listen(LocaleUpdated::class, function (LocaleUpdated $event): void {
            $dateLocale = LanguageRules::dateLocale($event->locale);

            if ($dateLocale === $event->locale) {
                return;
            }

            Carbon::setLocale($dateLocale);
            CarbonImmutable::setLocale($dateLocale);
            CarbonPeriod::setLocale($dateLocale);
            CarbonInterval::setLocale($dateLocale);
            IlluminateCarbon::setLocale($dateLocale);
        });
    }
}
