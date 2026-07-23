<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\Tag;

it('applies a complete preset through the setup command', function () {
    $this->artisan('rateguru:setup', ['preset' => 'nature'])
        ->expectsConfirmation(
            'Apply preset [nature]? This replaces project settings, rating configuration, and tags.',
            'yes',
        )
        ->expectsOutput('Preset [nature] applied successfully.')
        ->assertExitCode(0);

    expect(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature')
        ->and(ProjectSettings::firstOrFail()->preset_applied_at)->not->toBeNull()
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
            'Apply preset [ai_images]? This replaces project settings, rating configuration, and tags.',
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
            'Apply preset [nature]? This replaces project settings, rating configuration, and tags.',
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
