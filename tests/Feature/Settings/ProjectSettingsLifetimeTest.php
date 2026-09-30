<?php

use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Theme\ThemeManager;
use Illuminate\Support\Facades\DB;

/**
 * Project settings are cached for one request or one queued job — never for
 * the life of a queue worker.
 *
 * Between jobs the worker calls forgetScopedInstances(); that is the whole of
 * what these tests do to end a scope. A settings cache that outlived it would
 * keep a long-running worker on the languages and defaults it started with,
 * after an administrator changed them.
 */

/** Whether a class reaches the settings manager through its constructor. */
function reachesProjectSettings(string $class, array $seen = []): bool
{
    if ($class === ProjectSettingsManager::class) {
        return true;
    }

    if (isset($seen[$class]) || ! class_exists($class)) {
        return false;
    }

    $seen[$class] = true;

    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && reachesProjectSettings($type->getName(), $seen)) {
            return true;
        }
    }

    return false;
}

it('is shared within a request or job and replaced for the next one', function () {
    $first = app(ProjectSettingsManager::class);

    expect(app(ProjectSettingsManager::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(ProjectSettingsManager::class))->not->toBe($first);
});

it('serves the next job the languages as they are now, not as the worker first saw them', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['enabled_locales' => null, 'default_locale' => $offered]);
    $reader = User::factory()->create(['locale' => $withheld]);

    // A job reads the settings: every language is on offer.
    expect(app(LocaleManager::class)->isEnabled($withheld))->toBeTrue()
        ->and($reader->preferredLocale())->toBe($withheld);

    // Between jobs, an administrator withdraws a language.
    DB::table('project_settings')->where('id', 1)->update([
        'enabled_locales' => json_encode(array_values(array_diff(supportedLocales(), [$withheld]))),
    ]);

    // Within the same job the cached settings still stand...
    expect(app(LocaleManager::class)->isEnabled($withheld))->toBeTrue();

    // ...and the next job starts from the database again.
    app()->forgetScopedInstances();

    expect(app(LocaleManager::class)->isEnabled($withheld))->toBeFalse()
        ->and($reader->fresh()->preferredLocale())->toBeNull();
});

it('does not let the theme manager hold on to an earlier scope', function () {
    ProjectSettings::factory()->create(['default_theme' => 'dark']);

    expect(app(ThemeManager::class)->defaultPreference())->toBe('dark');

    DB::table('project_settings')->where('id', 1)->update(['default_theme' => 'light']);
    app()->forgetScopedInstances();

    expect(app(ThemeManager::class)->defaultPreference())->toBe('light');
});

it('keeps no application singleton that holds the settings manager', function () {
    // A singleton built on the settings manager would capture the first
    // scope's instance, and with it the first scope's settings, for good.
    $captive = [];

    foreach (array_keys(app()->getBindings()) as $abstract) {
        if (! str_starts_with($abstract, 'App\\') || ! app()->isShared($abstract)) {
            continue;
        }

        $instance = app($abstract);
        app()->forgetScopedInstances();

        if (app($abstract) === $instance && reachesProjectSettings($instance::class)) {
            $captive[] = $abstract;
        }
    }

    expect($captive)->toBe([]);
});
