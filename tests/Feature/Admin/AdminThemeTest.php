<?php

use App\Models\User;

/**
 * The admin panel's custom Filament theme: one compiled Vite entry that layers
 * the Admin v2 tokens and components on Filament's own theme, and carries the
 * two fixes to Filament components that used to be an inline stylesheet.
 */
const ADMIN_THEME_ENTRY = 'resources/css/filament/admin/theme.css';

/**
 * The colour an Admin v2 token resolves to, following var() references through
 * tokens.css, as a lowercase #rrggbb.
 */
function adminThemeTokenColour(string $token): string
{
    $tokens = (string) file_get_contents(resource_path('css/filament/admin/tokens.css'));

    expect(preg_match('/^\s*'.preg_quote($token, '/').':\s*([^;]+);/m', $tokens, $match))
        ->toBe(1, "tokens.css does not declare {$token}");

    $value = trim($match[1]);

    if (preg_match('/^var\((--[a-z0-9-]+)\)$/', $value, $reference) === 1) {
        return adminThemeTokenColour($reference[1]);
    }

    expect($value)->toMatch('/^#[0-9a-f]{6}$/i', "{$token} is not a plain #rrggbb colour");

    return strtolower($value);
}

/** WCAG 2 relative luminance of a #rrggbb colour. */
function wcagRelativeLuminance(string $hex): float
{
    $channels = array_map(function (string $pair): float {
        $channel = hexdec($pair) / 255;

        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** WCAG 2 contrast ratio between two #rrggbb colours. */
function wcagContrastRatio(string $foreground, string $background): float
{
    $lighter = max(wcagRelativeLuminance($foreground), wcagRelativeLuminance($background));
    $darker = min(wcagRelativeLuminance($foreground), wcagRelativeLuminance($background));

    return ($lighter + 0.05) / ($darker + 0.05);
}

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
        // A nested rule's capture starts at the declarations before it; keep only the selector.
        ->map(fn (string $capture): string => str_contains($capture, ';') ? substr($capture, strrpos($capture, ';') + 1) : $capture)
        // Split selector lists, but not the commas inside :not(…).
        ->flatMap(fn (string $selector): array => preg_split('/,(?![^(]*\))/', $selector) ?: [])
        // A nested selector (& .child) hangs off a parent that is itself checked.
        ->map(fn (string $selector): string => preg_replace('/^&\s*/', '', trim($selector)) ?? '')
        // Keyframe steps are not selectors.
        ->reject(fn (string $selector): bool => preg_match('/^(\d+%|from|to)$/', $selector) === 1)
        ->filter();

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, '.rg-admin'))->values()->all())->toBe([])
        ->and($components)->not->toContain('.fi-');
});

it('measures contrast the way WCAG does', function () {
    // The helper is only as good as its maths: pin it to known ratios.
    expect(round(wcagContrastRatio('#000000', '#ffffff'), 2))->toBe(21.0)
        ->and(round(wcagContrastRatio('#ffffff', '#ffffff'), 2))->toBe(1.0)
        ->and(round(wcagContrastRatio('#99a0ae', '#ffffff'), 2))->toBe(2.63)
        ->and(round(wcagContrastRatio('#68707d', '#f6f7fb'), 2))->toBe(4.67);
});

it('draws tertiary text with WCAG AA contrast on cards and on the app ground', function () {
    // The reference draws tertiary text in gray-400, 2.63:1 on white. The
    // admin deliberately departs from it for text people have to read.
    $tertiary = adminThemeTokenColour('--rg-admin-text-tertiary');

    foreach (['#ffffff', '#f6f7fb'] as $background) {
        expect(wcagContrastRatio($tertiary, $background))->toBeGreaterThanOrEqual(4.5);
    }

    foreach (['--rg-admin-surface-card', '--rg-admin-surface-app', '--rg-admin-surface-sunken'] as $surface) {
        expect(wcagContrastRatio($tertiary, adminThemeTokenColour($surface)))->toBeGreaterThanOrEqual(4.5);
    }

    expect($tertiary)->toBe('#68707d');
});

it('keeps strong, secondary and tertiary text readable and distinct', function () {
    $strong = adminThemeTokenColour('--rg-admin-text-strong');
    $secondary = adminThemeTokenColour('--rg-admin-text-secondary');
    $tertiary = adminThemeTokenColour('--rg-admin-text-tertiary');

    foreach ([$strong, $secondary, $tertiary] as $text) {
        expect(wcagContrastRatio($text, '#ffffff'))->toBeGreaterThanOrEqual(4.5)
            ->and(wcagContrastRatio($text, '#f6f7fb'))->toBeGreaterThanOrEqual(4.5);
    }

    // Each step of the hierarchy is lighter than the one above it.
    expect(wcagContrastRatio($tertiary, '#ffffff'))->toBeLessThan(wcagContrastRatio($secondary, '#ffffff'))
        ->and(wcagContrastRatio($secondary, '#ffffff'))->toBeLessThan(wcagContrastRatio($strong, '#ffffff'));
});

it('keeps the reference palette as delivered', function () {
    // Tertiary text departs from gray-400; gray-400 itself does not change.
    expect(adminThemeTokenColour('--rg-admin-gray-400'))->toBe('#99a0ae')
        ->and(adminThemeTokenColour('--rg-admin-gray-600'))->toBe('#525866')
        ->and(adminThemeTokenColour('--rg-admin-gray-50'))->toBe('#f6f7fb')
        ->and(adminThemeTokenColour('--rg-admin-text-tertiary'))->not->toBe(adminThemeTokenColour('--rg-admin-gray-400'));
});
