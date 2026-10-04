<?php

use App\Models\User;

/**
 * The admin panel's custom Filament theme: one compiled Vite entry that layers
 * the Admin v2 tokens and components on Filament's own theme, and carries the
 * two fixes to Filament components that used to be an inline stylesheet.
 */
const ADMIN_THEME_ENTRY = 'resources/css/filament/admin/theme.css';

it('gives the admin panel its own Vite theme', function () {
    expect(filament()->getPanel('admin')->getViteTheme())->toBe(ADMIN_THEME_ENTRY);
});

it('builds the admin theme alongside the application assets', function () {
    $config = (string) file_get_contents(base_path('vite.config.js'));

    preg_match('/input:\s*\[([^\]]*)\]/', $config, $input);

    expect($input[1] ?? '')
        ->toContain("'resources/css/app.css'")
        ->toContain("'resources/js/app.js'")
        ->toContain("'".ADMIN_THEME_ENTRY."'");
});

it('layers the Admin v2 tokens and components on Filament\'s own theme', function () {
    $theme = (string) file_get_contents(base_path(ADMIN_THEME_ENTRY));

    preg_match_all("/@import\s+'([^']+)';/", $theme, $imports);
    preg_match_all("/@source\s+'([^']+)';/", $theme, $sources);

    expect($imports[1])->toBe([
        '../../../../vendor/filament/filament/resources/css/theme.css',
        './tokens.css',
        './components.css',
    ])->and($sources[1])->toBe([
        '../../../../app/Filament/**/*',
        '../../../../resources/views/filament/**/*',
        '../../../../resources/views/components/admin/**/*',
    ]);

    foreach ($imports[1] as $import) {
        expect(realpath(dirname(base_path(ADMIN_THEME_ENTRY)).'/'.$import))->not->toBeFalse("{$import} does not exist");
    }
});

it('keeps admin styling in the compiled theme rather than an inline stylesheet', function () {
    $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    $theme = (string) file_get_contents(base_path(ADMIN_THEME_ENTRY));

    expect(resource_path('views/filament/admin/styles.blade.php'))->not->toBeFile()
        ->and($provider)->not->toContain('PanelsRenderHook')
        ->and($provider)->not->toContain('filament.admin.styles')
        // The two fixes the inline stylesheet carried now live in the theme.
        ->and($theme)->toContain("input[type='checkbox']:not(:disabled)")
        ->and($theme)->toContain("input[type='file']:not(:disabled)::file-selector-button")
        ->and($theme)->toContain('.fi-pagination-records-per-page-select .fi-input-wrp:focus-within');
});

it('loads the compiled theme on admin pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('build/assets/theme-', false)
        ->assertDontSee('.fi-pagination-records-per-page-select .fi-input-wrp', false);
});

it('keeps the theme additive: Admin v2 rules never restyle Filament components', function () {
    $components = (string) file_get_contents(resource_path('css/filament/admin/components.css'));

    // Every selector in the component CSS starts from an Admin v2 class.
    preg_match_all('/^\s*([^@\s{}\/*][^{}]*)\{/m', $components, $rules);

    $selectors = collect($rules[1])
        // Split selector lists, but not the commas inside :not(…).
        ->flatMap(fn (string $selector): array => preg_split('/,(?![^(]*\))/', $selector) ?: [])
        ->map(fn (string $selector): string => trim($selector))
        // Keyframe steps are not selectors.
        ->reject(fn (string $selector): bool => preg_match('/^(\d+%|from|to)$/', $selector) === 1)
        ->filter();

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, '.rg-admin'))->values()->all())->toBe([])
        ->and($components)->not->toContain('.fi-');
});
