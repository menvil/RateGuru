<?php

use Symfony\Component\Finder\Finder;

/**
 * The public UI and Admin v2 are two design systems that share no visual
 * tokens (docs/design/admin/design-contract.md). This keeps them apart in both
 * directions, and keeps reusable admin components on Admin v2 tokens rather
 * than Tailwind palette colours, so a redesign changes one file.
 *
 * Scope is the two design systems' own files, not the whole repository.
 */

/**
 * What ties an Admin v2 file to the public design system, by kind.
 *
 * @return array<string, string>
 */
function publicDesignSystemPatterns(): array
{
    return [
        'a public x-ui component' => '/<x-ui[.:]|x-ui::/',
        'a public --rg-* token' => '/--rg-(?!admin-)[a-z0-9]/',
        // Filament's own theme sits at vendor/filament/filament/resources/css/theme.css.
        'the public theme stylesheet' => '/(?:(?<!filament\/)resources\/css\/|[\'"]\.\.\/)(?:theme|app)\.css/',
    ];
}

/** A Tailwind palette colour, which a reusable admin component must take from a token instead. */
const TAILWIND_PALETTE_COLOUR = '/(?<![\w-])(?:[a-z]+:)*(?:bg|text|border|ring|fill|stroke|outline|divide|from|via|to|shadow|accent|caret|decoration|placeholder)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|black|white)(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/';

/**
 * The kinds of public design-system use found in some admin source.
 *
 * @return list<string>
 */
function publicDesignSystemUses(string $source): array
{
    return array_keys(array_filter(
        publicDesignSystemPatterns(),
        fn (string $pattern): bool => preg_match($pattern, $source) === 1,
    ));
}

/** @return iterable<SplFileInfo> */
function adminDesignSystemFiles(): iterable
{
    return Finder::create()->files()
        ->in([resource_path('views/components/admin'), resource_path('css/filament/admin')])
        ->name(['*.blade.php', '*.css']);
}

it('finds the files it guards', function () {
    $files = collect(adminDesignSystemFiles())->map(fn (SplFileInfo $file): string => $file->getFilename());

    expect($files)->toContain('button.blade.php', 'icon.blade.php', 'theme.css', 'tokens.css', 'components.css');
});

it('keeps Admin v2 off the public components, tokens and stylesheets', function () {
    $problems = [];

    foreach (adminDesignSystemFiles() as $file) {
        foreach (publicDesignSystemUses($file->getContents()) as $use) {
            $problems[] = "{$file->getRelativePathname()} uses {$use}";
        }
    }

    expect($problems)->toBe([]);
});

it('draws reusable admin components in Admin v2 tokens, not Tailwind palette colours', function () {
    $problems = [];

    foreach (Finder::create()->files()->in(resource_path('views/components/admin'))->name('*.blade.php') as $file) {
        if (preg_match_all(TAILWIND_PALETTE_COLOUR, $file->getContents(), $matches) > 0) {
            $problems[] = $file->getRelativePathname().' uses '.implode(', ', array_unique($matches[0]));
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the public design system off Admin v2', function () {
    $problems = [];

    $public = Finder::create()->files()
        ->in(resource_path('views/components/ui'))
        ->name('*.blade.php')
        ->append([new SplFileInfo(resource_path('css/app.css')), new SplFileInfo(resource_path('css/theme.css'))]);

    foreach ($public as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/x-admin[.:]|--rg-admin-|rg-admin-/', $source) === 1) {
            $problems[] = $file->getPathname();
        }
    }

    expect($problems)->toBe([]);
});

it('recognises what it guards against, and nothing more', function () {
    // The guard is only as good as its patterns: one that silently stopped
    // matching would pass every file, and one that matched the admin's own
    // --rg-admin-* tokens would fail every file.
    expect(publicDesignSystemUses('<x-ui.button>Save</x-ui.button>'))->toBe(['a public x-ui component'])
        ->and(publicDesignSystemUses('color: var(--rg-muted);'))->toBe(['a public --rg-* token'])
        ->and(publicDesignSystemUses("@import '../theme.css';"))->toBe(['the public theme stylesheet'])
        ->and(publicDesignSystemUses("@import 'resources/css/app.css';"))->toBe(['the public theme stylesheet'])
        ->and(publicDesignSystemUses('color: var(--rg-admin-text-strong); <x-admin.ui.button />'))->toBe([])
        ->and(publicDesignSystemUses("@import '../../../../vendor/filament/filament/resources/css/theme.css';"))->toBe([])
        ->and(publicDesignSystemUses("@import './tokens.css';"))->toBe([]);

    expect(preg_match(TAILWIND_PALETTE_COLOUR, 'class="bg-gray-50"'))->toBe(1)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'class="dark:text-gray-400"'))->toBe(1)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'class="border-red-950/40"'))->toBe(1)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'class="text-white"'))->toBe(1)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'class="rg-admin-badge--success"'))->toBe(0)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'background: var(--rg-admin-gray-50)'))->toBe(0)
        ->and(preg_match(TAILWIND_PALETTE_COLOUR, 'color: var(--rg-admin-text-tertiary)'))->toBe(0);
});
