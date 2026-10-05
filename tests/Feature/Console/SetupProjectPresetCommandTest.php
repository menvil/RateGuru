<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\Tag;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('refuses to apply a preset non-interactively without --force, instead of reporting success', function () {
    // Without a terminal, confirm() resolves to its own default — false — so the
    // command used to print "Setup cancelled." and exit 0. A deployment script
    // could not tell that from an applied preset.
    $input = new ArrayInput(['command' => 'rateguru:setup', 'preset' => 'nature']);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    $status = app(Kernel::class)->handle($input, $output);

    expect($status)->toBe(1)
        ->and($output->fetch())->toContain('requires --force');

    // And nothing was applied.
    expect(ProjectSettings::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);
});

it('applies a preset non-interactively when --force says so', function () {
    $input = new ArrayInput(['command' => 'rateguru:setup', 'preset' => 'nature', '--force' => true]);
    $input->setInteractive(false);

    $status = app(Kernel::class)->handle($input, new BufferedOutput);

    expect($status)->toBe(0)
        ->and(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature');
});
