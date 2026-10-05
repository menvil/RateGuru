<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\Tag;
use App\Support\Settings\ProjectSettingsManager;

it('applies a complete preset through the setup command', function () {
    $this->artisan('rateguru:setup', ['preset' => 'nature'])
        ->expectsConfirmation(
            'Apply preset [nature]? This replaces project settings, categories, rating configuration, and tags.',
            'yes',
        )
        ->expectsOutput('Preset [nature] applied successfully.')
        ->assertExitCode(0);

    expect(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature')
        ->and(ProjectSettings::firstOrFail()->preset_applied_at)->not->toBeNull()
        ->and(Category::query()->active()->count())->toBe(count(config('project_presets.nature.categories')))
        ->and(RatingGroup::query()->active()->count())->toBe(2)
        ->and(Tag::query()->count())->toBe(count(config('project_presets.nature.tags')));
});

it('refuses to run a second time without force', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now()->subDay(),
    ]);

    $this->artisan('rateguru:setup', ['preset' => 'ai_images'])
        ->expectsConfirmation(
            'Apply preset [ai_images]? This replaces project settings, categories, rating configuration, and tags.',
            'yes',
        )
        ->expectsOutput('A project preset has already been applied. Use --force to replace it deliberately.')
        ->assertExitCode(1);

    expect(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature');
});

it('refuses to configure a site that already has posts', function () {
    Post::factory()->create();

    $this->artisan('rateguru:setup', ['preset' => 'nature'])
        ->expectsConfirmation(
            'Apply preset [nature]? This replaces project settings, categories, rating configuration, and tags.',
            'yes',
        )
        ->expectsOutput('Project content already exists. Use --force only after reviewing the destructive preset changes.')
        ->assertExitCode(1);

    expect(ProjectSettings::query()->where('active_preset_key', 'nature')->exists())->toBeFalse();
});

it('allows explicit forced reapplication without another confirmation', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now()->subDay(),
    ]);

    $this->artisan('rateguru:setup', [
        'preset' => 'ai_images',
        '--force' => true,
    ])
        ->expectsOutput('Preset [ai_images] applied successfully.')
        ->assertExitCode(0);

    expect(ProjectSettings::firstOrFail()->active_preset_key)->toBe('ai_images');
});

it('rejects an unknown preset key', function () {
    $this->artisan('rateguru:setup', ['preset' => 'unknown'])
        ->expectsOutput('Unknown project preset: [unknown].')
        ->assertExitCode(1);
});

it('keeps the languages the project offers when setup is forced again', function () {
    [, $only] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['active_preset_key' => 'generic', 'preset_applied_at' => now()->subDay()]);
    offerLocales([$only]);
    $offered = ProjectSettings::firstOrFail()->enabled_locales;

    $this->artisan('rateguru:setup', ['preset' => 'nature', '--force' => true])
        ->expectsOutput('Preset [nature] applied successfully.')
        ->assertExitCode(0);

    expect(ProjectSettings::firstOrFail())
        ->active_preset_key->toBe('nature')
        ->enabled_locales->toBe($offered);
});

it('sets up a new project with the same static pages as every other bootstrap', function () {
    $this->artisan('rateguru:setup', ['preset' => 'nature', '--force' => true])->assertExitCode(0);

    expect(ProjectSettings::firstOrFail()->static_pages)->toBe(app(ProjectSettingsManager::class)->defaults()['static_pages']);
});

/*
 * Two different contracts, and the command used to satisfy neither.
 *
 *   --no-interaction   the caller SAYS not to ask
 *   no terminal        there is nobody to ask
 *
 * isInteractive() reports only the first. So `rateguru:setup nature < /dev/null`
 * reached confirm(), took its default of "no", printed "Setup cancelled." and
 * exited 0 — indistinguishable, to a deploy script, from an applied preset.
 */

it('refuses --no-interaction without --force instead of reporting success', function () {
    $this->artisan('rateguru:setup', ['preset' => 'nature', '--no-interaction' => true])
        ->expectsOutputToContain('requires --force')
        ->assertExitCode(1);

    expect(ProjectSettings::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);
});

it('applies a preset with --no-interaction when --force says so', function () {
    $this->artisan('rateguru:setup', ['preset' => 'nature', '--no-interaction' => true, '--force' => true])
        ->assertExitCode(0);

    expect(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature');
});

it('refuses a run whose stdin is not a terminal, without being told not to ask', function () {
    // Run as a real subprocess with stdin closed, because that is the only way to
    // exercise this honestly: in-process the question helper is mocked and the
    // test runner legitimately counts as answerable. No --no-interaction flag is
    // passed — the absence of a terminal is the whole point.
    //
    // Nothing is written on this path: the refusal happens before the preset
    // action is reached, which the exit code and the untouched tables below pin.
    $process = proc_open(
        [PHP_BINARY, 'artisan', 'rateguru:setup', 'nature'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        // Deliberately NOT APP_ENV=testing: runningUnitTests() is keyed off it,
        // and setting it would hand the subprocess the very allowance this test
        // exists to run without.
        array_filter($_SERVER, 'is_string'),
    );

    expect($process)->not->toBeFalse('the CLI subprocess must start');

    try {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    }

    expect($status)->toBe(1, "the run must refuse:\n{$stdout}{$stderr}")
        ->and($stdout.$stderr)->toContain('requires --force');
});
