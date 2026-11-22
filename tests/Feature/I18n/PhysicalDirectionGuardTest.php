<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * The public site names sides only as start and end.
 *
 * A page in a language read right to left is the same page with dir="rtl" on
 * its root (layouts read it from config/locales.php). That turns the whole
 * layout round only while nothing in it says "left" or "right": a margin, an
 * edge, an alignment or a slide named by its physical side stays where it is
 * and puts the page out of joint. So the public templates, the public
 * stylesheets and the public scripts use the logical forms — ms-/me-, ps-/pe-,
 * inset-s-/inset-e-, text-start/end, rounded-s/e, border-s/e in Tailwind;
 * margin-inline-start, inset-inline, text-align: start in CSS — which read
 * exactly as before on a page read left to right, and turn on one read right
 * to left. (Tailwind 4's space-x and divide-x are logical already.)
 *
 * Where nothing logical exists — a slide, a transform origin — the physical
 * form is written once per direction under ltr: and rtl:, and is fine. What is
 * left physical on purpose is in the allow-lists below, each with why.
 *
 * The public site is everything a visitor or a reader is served: every Blade
 * view but the administration's, resources/css/app.css and theme.css, and
 * resources/js. The administration stays in English and left to right.
 */

/**
 * Views that are not the public site.
 *
 * @var array<string, string>
 */
const PHYSICAL_DIRECTION_EXEMPT_VIEWS = [
    // The administration: Filament's own pages and the components only they
    // use. It is served in English, left to right, whatever the visitor's
    // language (SetAdminLocale).
    'filament/' => 'the administration',
    'components/admin/' => 'the administration',
    'livewire/admin/' => 'the administration',
    // The UI kit: a reference page served in local development only
    // (EnsureDevEnvironment), never to a visitor.
    'dev/' => 'a development page',
];

/**
 * Utilities left physical on purpose, by view.
 *
 * @var array<string, array<string, string>>
 */
const PHYSICAL_DIRECTION_ALLOWED_UTILITIES = [
    'components/ui/image-placeholder.blade.php' => [
        // Centring: half across and back by half its own width lands in the
        // middle whichever way the line runs.
        'left-1/2' => 'centring',
        '-translate-x-1/2' => 'centring',
    ],
];

/**
 * Declarations left physical on purpose, by view, as the guard reports them.
 *
 * @var array<string, array<string, string>>
 */
const PHYSICAL_DIRECTION_ALLOWED_DECLARATIONS = [
    // An email is read in mail clients, many of which know no logical side and
    // keep only what was inlined into each element's style: its right-to-left
    // block, written only into an email in such a language, turns the theme's
    // left-aligned text and left-edged panel by naming the sides.
    'vendor/mail/html/layout.blade.php' => [
        'text-align: right' => 'email',
        'border-left: 0' => 'email',
        'border-right: #18181b solid 4px' => 'email',
    ],
];

/**
 * Script that reads or sets a horizontal position, or hears the left and
 * right arrow keys, by file — each place says here how it takes the page's
 * direction into account. None today.
 *
 * @var array<string, list<string>>
 */
const PHYSICAL_DIRECTION_ALLOWED_SCRIPT = [];

/**
 * A Tailwind class split into its variants and the utility itself, on the
 * colons outside brackets and parentheses: `md:[&>svg]:ml-2` is md, [&>svg]
 * and ml-2.
 *
 * @return array{0: list<string>, 1: string}
 */
function physicalDirectionSplitVariants(string $class): array
{
    $depth = 0;
    $parts = [];
    $start = 0;

    for ($i = 0, $length = strlen($class); $i < $length; $i++) {
        $character = $class[$i];

        if ($character === '[' || $character === '(') {
            $depth++;
        } elseif ($character === ']' || $character === ')') {
            $depth--;
        } elseif ($character === ':' && $depth === 0) {
            $parts[] = substr($class, $start, $i - $start);
            $start = $i + 1;
        }
    }

    return [$parts, substr($class, $start)];
}

/**
 * Every Tailwind utility in a template or a script that names a physical side,
 * as written — variants and all. Blade comments are prose and are left out; a
 * utility under ltr: or rtl: is written for one direction and is fine; a
 * translation by nothing goes nowhere.
 *
 * @return list<string>
 */
function physicalDirectionUtilities(string $source): array
{
    $length = '(?:\d+(?:\.\d+)?|\d+/\d+|px|full|auto|screen|\[[^\]]+\]|\([^)]+\))';
    $physical = '~^(?:'
        .'(?:scroll-)?[mp][lr]-'.$length
        .'|(?:left|right)-'.$length
        .'|translate-x-'.$length
        .'|text-(?:left|right)'
        .'|(?:float|clear)-(?:left|right)'
        .'|rounded-(?:[lr]|tl|tr|bl|br)(?:-[\w\[\]./-]+)?'
        .'|border-[lr](?:-[\w\[\]./#-]+)?'
        .'|origin-(?:left|right|(?:top|bottom)-(?:left|right))'
        .'|(?:bg|object)-(?:left|right|(?:top|bottom)-(?:left|right)|(?:left|right)-(?:top|bottom))'
        .'|bg-(?:linear|gradient)-to-[lr]'
        .')$~';

    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $found = [];

    // Split where a class list ends — spaces, quotes, commas, braces — but not on
    // the brackets of an arbitrary variant such as [&>svg]: .
    foreach (preg_split('/[\s"\'`,{}=]+/', $source) ?: [] as $class) {
        if ($class === '') {
            continue;
        }

        [$variants, $utility] = physicalDirectionSplitVariants($class);
        $bare = ltrim(trim($utility, '!'), '-');

        if (array_intersect($variants, ['ltr', 'rtl']) !== [] || $bare === 'translate-x-0') {
            continue;
        }

        if (preg_match($physical, $bare) === 1) {
            $found[] = $class;
        }
    }

    return $found;
}

/**
 * The values of a declaration, split on the spaces outside parentheses.
 *
 * @return list<string>
 */
function physicalDirectionValues(string $value): array
{
    $values = [];
    $current = '';
    $depth = 0;

    foreach (str_split(trim($value)) as $character) {
        $depth += match ($character) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };

        if (ctype_space($character) && $depth === 0) {
            if ($current !== '') {
                $values[] = $current;
            }

            $current = '';

            continue;
        }

        $current .= $character;
    }

    if ($current !== '') {
        $values[] = $current;
    }

    return $values;
}

/**
 * Every declaration in a list of them — a rule's body or a style attribute —
 * that names a physical side, as `property: value`: a left or right property,
 * a left or right alignment or float, a shorthand whose left and right differ,
 * a corner radius that is not the same on both sides, a sideways translation,
 * a transform origin or background position at a side, and a gradient across
 * the line — which fades towards one side. A diagonal gradient is lighting,
 * and is left alone.
 *
 * @return list<string>
 */
function physicalDirectionInDeclarations(string $declarations): array
{
    $found = [];

    foreach (explode(';', $declarations) as $declaration) {
        if (! str_contains($declaration, ':')) {
            continue;
        }

        [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));
        $property = strtolower($property);
        $value = trim((string) preg_replace('/\s*!important\s*$/i', '', $value));
        $lower = strtolower($value);
        $values = physicalDirectionValues($lower);

        $physical = match (true) {
            preg_match('/^(?:(?:margin|padding|scroll-margin|scroll-padding)-(?:left|right)|left|right|border-(?:left|right)(?:-(?:width|style|color))?|border-(?:top|bottom)-(?:left|right)-radius)$/', $property) === 1 => true,
            in_array($property, ['text-align', 'float', 'clear', 'justify-self', 'justify-items'], true) => in_array($lower, ['left', 'right'], true),
            in_array($property, ['margin', 'padding', 'inset', 'border-width', 'border-style', 'border-color', 'scroll-margin', 'scroll-padding'], true) => count($values) === 4 && $values[1] !== $values[3],
            $property === 'border-radius' => (function () use ($lower): bool {
                $corners = physicalDirectionValues(explode('/', $lower)[0]);

                return match (count($corners)) {
                    2 => $corners[0] !== $corners[1],
                    3 => $corners[0] !== $corners[1] || $corners[2] !== $corners[1],
                    4 => $corners[0] !== $corners[1] || $corners[2] !== $corners[3],
                    default => false,
                };
            })(),
            $property === 'translate' => isset($values[0]) && preg_match('/^-?0(?:px|%)?$/', $values[0]) !== 1,
            in_array($property, ['transform-origin', 'background-position', 'object-position', 'perspective-origin'], true) => preg_match('/\b(?:left|right)\b/', $lower) === 1,
            default => false,
        };

        $physical = $physical
            || preg_match('/translatex\(\s*(?!-?0(?:px|%)?\s*\))/', $lower) === 1
            || preg_match('/translate\(\s*(?!-?0(?:px|%)?\s*[,)])/', $lower) === 1 && $property !== 'translate'
            || preg_match('/linear-gradient\(\s*(?:to\s+(?:left|right)\s*,|(?:90|270)deg)/', $lower) === 1;

        if ($physical) {
            $found[] = "{$property}: {$value}";
        }
    }

    return $found;
}

/**
 * Every declaration in a stylesheet that names a physical side: the bodies of
 * its rules are the innermost braces, whatever @media or @layer holds them.
 *
 * @return list<string>
 */
function physicalDirectionInStylesheet(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/\{([^{}]*)\}/', $css, $bodies);

    return array_merge([], ...array_map(physicalDirectionInDeclarations(...), $bodies[1]));
}

/**
 * Every declaration in a template that names a physical side: in its <style>
 * blocks and in its style attributes, bound or not.
 *
 * @return list<string>
 */
function physicalDirectionInTemplateStyles(string $source): array
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $found = [];

    preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $source, $blocks);

    foreach ($blocks[1] as $block) {
        array_push($found, ...physicalDirectionInStylesheet($block));
    }

    preg_match_all('~(?<![\w-])(?:x-bind:|:)?style="([^"]*)"~i', $source, $attributes);

    foreach ($attributes[1] as $attribute) {
        array_push($found, ...physicalDirectionInDeclarations(trim($attribute, '`')));
    }

    return $found;
}

/**
 * Every place in a script — a file of its own or one in a template — that
 * reads or sets a horizontal position, or hears the left or right arrow key.
 *
 * @return list<string>
 */
function physicalDirectionInScript(string $source): array
{
    preg_match_all('/\b(?:ArrowLeft|ArrowRight|scrollLeft|offsetLeft|clientX|pageX|screenX|movementX|deltaX)\b|\.style\.(?:left|right|margin(?:Left|Right)|padding(?:Left|Right))\b|keydown\.(?:left|right|arrow-left|arrow-right)\b/', $source, $matches);

    return array_values(array_unique($matches[0]));
}

/** @return array<string, string> the public views, by their path under resources/views */
function physicalDirectionPublicViews(): array
{
    $views = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        foreach (array_keys(PHYSICAL_DIRECTION_EXEMPT_VIEWS) as $exempt) {
            if (str_starts_with($relative, $exempt)) {
                continue 2;
            }
        }

        $views[$relative] = $file->getContents();
    }

    return $views;
}

/** @return array<string, string> the public scripts, by their path under resources/js */
function physicalDirectionPublicScripts(): array
{
    $scripts = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name('*.js')->sortByName() as $file) {
        $scripts[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $scripts;
}

it('names sides only logically in the public templates', function () {
    $problems = [];

    foreach (physicalDirectionPublicViews() as $view => $source) {
        $allowed = PHYSICAL_DIRECTION_ALLOWED_UTILITIES[$view] ?? [];

        foreach (physicalDirectionUtilities($source) as $class) {
            if (! array_key_exists($class, $allowed)) {
                $problems[] = "resources/views/{$view}: {$class}";
            }
        }
    }

    expect($problems)->toBe([], "A physical side in a public template: use the logical utility, or ltr: and rtl: where there is none.\n".implode("\n", $problems));
});

it('names sides only logically in the public templates\' own styles', function () {
    $problems = [];

    foreach (physicalDirectionPublicViews() as $view => $source) {
        $allowed = PHYSICAL_DIRECTION_ALLOWED_DECLARATIONS[$view] ?? [];

        foreach (physicalDirectionInTemplateStyles($source) as $declaration) {
            if (! array_key_exists($declaration, $allowed)) {
                $problems[] = "resources/views/{$view}: {$declaration}";
            }
        }
    }

    expect($problems)->toBe([], "A physical side in a public template's styles: use the logical property.\n".implode("\n", $problems));
});

it('names sides only logically in the public stylesheets', function (string $stylesheet) {
    $problems = physicalDirectionInStylesheet(File::get(resource_path($stylesheet)));

    expect($problems)->toBe([], "A physical side in resources/{$stylesheet}: use the logical property.\n".implode("\n", $problems));
})->with(['css/app.css', 'css/theme.css']);

it('names sides only logically in the public scripts', function () {
    $problems = [];

    foreach (physicalDirectionPublicScripts() as $script => $source) {
        foreach (physicalDirectionUtilities($source) as $class) {
            $problems[] = "resources/js/{$script}: {$class}";
        }
    }

    expect($problems)->toBe([], "A physical side in a public script: use the logical utility, or ltr: and rtl: where there is none.\n".implode("\n", $problems));
});

it('takes the page direction into account wherever public script reads a horizontal position or the arrow keys', function () {
    $problems = [];

    $sources = [
        ...array_combine(array_map(fn (string $script): string => "resources/js/{$script}", array_keys(physicalDirectionPublicScripts())), physicalDirectionPublicScripts()),
        ...array_combine(array_map(fn (string $view): string => "resources/views/{$view}", array_keys(physicalDirectionPublicViews())), physicalDirectionPublicViews()),
    ];

    foreach ($sources as $path => $source) {
        foreach (array_diff(physicalDirectionInScript($source), PHYSICAL_DIRECTION_ALLOWED_SCRIPT[$path] ?? []) as $use) {
            $problems[] = "{$path}: {$use}";
        }
    }

    expect($problems)->toBe([], "Script reading a horizontal position or the arrow keys: make it follow the page's direction, then list it in PHYSICAL_DIRECTION_ALLOWED_SCRIPT saying how.\n".implode("\n", $problems));
});

it('keeps its allow-lists to what is still there', function () {
    $views = physicalDirectionPublicViews();

    foreach (PHYSICAL_DIRECTION_ALLOWED_UTILITIES as $view => $classes) {
        expect($views)->toHaveKey($view);

        foreach (array_keys($classes) as $class) {
            expect(physicalDirectionUtilities($views[$view]))->toContain($class);
        }
    }

    foreach (PHYSICAL_DIRECTION_ALLOWED_DECLARATIONS as $view => $declarations) {
        expect($views)->toHaveKey($view);

        foreach (array_keys($declarations) as $declaration) {
            expect(physicalDirectionInTemplateStyles($views[$view]))->toContain($declaration);
        }
    }
});

// The guard itself ----------------------------------------------------------

it('finds every shape a physical utility takes', function (string $class) {
    expect(physicalDirectionUtilities("<div class=\"flex {$class} gap-2\"></div>"))->toBe([$class])
        ->and(physicalDirectionUtilities("@class(['flex', '{$class}' => \$open])"))->toBe([$class])
        ->and(physicalDirectionUtilities("x-transition:enter-start=\"{$class}\""))->toBe([$class])
        ->and(physicalDirectionUtilities("\$el.classList.add('{$class}', 'shadow-none')"))->toBe([$class]);
})->with([
    'ml-4', 'mr-auto', '-ml-2', 'pl-3.5', 'pr-10', 'md:pr-[18px]', 'scroll-ml-4',
    'left-0', 'right-1/2', '-right-1', 'sm:right-0', 'left-[3px]', 'right-full',
    'text-left', 'sm:text-right', 'float-left', 'clear-right',
    'rounded-l', 'rounded-r-lg', 'rounded-tl-rgCard', 'rounded-br-[14px]',
    'border-l', 'border-r-0', 'border-l-4', 'md:border-l-rg-accent',
    'translate-x-full', '-translate-x-full', 'translate-x-1/2', 'group-hover:translate-x-1',
    'origin-top-right', 'origin-left', 'bg-left', 'object-right-top',
    'bg-gradient-to-r', 'bg-linear-to-l', '!ml-2', 'ml-2!', 'hover:[&>svg]:mr-1',
]);

it('leaves logical utilities, one-direction utilities and prose alone', function (string $source) {
    expect(physicalDirectionUtilities($source))->toBe([]);
})->with([
    'logical spacing' => ['<div class="ms-4 me-auto -ms-2 ps-3.5 pe-10 scroll-ms-4"></div>'],
    'logical insets' => ['<div class="inset-s-0 inset-e-1/2 -inset-e-1 start-0 end-0 inset-x-0"></div>'],
    'logical edges' => ['<div class="text-start text-end rounded-s rounded-ee-lg border-s border-e-0 float-start"></div>'],
    'symmetric' => ['<div class="mx-auto px-4 inset-x-0 space-x-2 divide-x rounded-lg border translate-x-0 -translate-y-1/2"></div>'],
    'written for each direction' => ['<div class="ltr:-translate-x-full rtl:translate-x-full ltr:origin-top-right rtl:origin-top-left md:rtl:left-0"></div>'],
    'a Blade comment' => ['{{-- the panel parks with its left edge (left-0) flush against the border-r --}}'],
    'words' => ['<p>Read from left to right, or right to left.</p>'],
    'Alpine attributes' => ['<div x-on:keydown.escape="open = false" x-transition:enter-end="opacity-100"></div>'],
    'a diagonal sheen' => ['<div class="bg-gradient-to-br bg-linear-to-tl from-rg-accent"></div>'],
]);

it('finds every shape a physical declaration takes', function (string $declaration) {
    expect(physicalDirectionInDeclarations($declaration))->toHaveCount(1)
        ->and(physicalDirectionInStylesheet(".x { color: red; {$declaration}; }"))->toHaveCount(1)
        ->and(physicalDirectionInStylesheet("@layer components { @media (min-width: 640px) { .x { {$declaration} } } }"))->toHaveCount(1);
})->with([
    'margin-left: 4px', 'padding-right: 1rem', 'left: 12px', 'right: 0', 'border-left: 1px solid red',
    'border-right-width: 2px', 'border-top-left-radius: 4px', 'scroll-margin-left: 8px',
    'text-align: left', 'text-align: right !important', 'float: right', 'clear: left',
    'margin: 0 4px 0 0', 'padding: 1px 2px 3px 4px', 'inset: 0 auto 0 0',
    'border-radius: 4px 0', 'border-radius: 4px 4px 0 4px',
    'transform: translateX(-100%)', 'transform: rotate(2deg) translate(10px, 0)', 'translate: 50% 0',
    'transform-origin: top right', 'background-position: left center',
    'background: linear-gradient(to right, red, blue)', 'background-image: linear-gradient(90deg, red, blue)',
]);

it('leaves logical and symmetric declarations alone', function (string $css) {
    expect(physicalDirectionInStylesheet(".x { {$css} }"))->toBe([]);
})->with([
    'logical' => ['margin-inline-start: 4px; padding-inline-end: 1rem; inset-inline-start: 12px; inset-inline: 0 auto; text-align: start; float: inline-start'],
    'symmetric shorthands' => ['margin: 0 auto; padding: 12px 16px 32px; inset: 0; border-radius: 8px; border-radius: 4px 4px 0 0; padding: 1px 2px 3px 2px'],
    'vertical' => ['transform: translateY(-3px); translate: 0 4px; background: linear-gradient(180deg, #16161f, #0d0d14); text-align: center'],
    'lighting' => ['background: linear-gradient(135deg, red 0%, blue 100%), radial-gradient(circle at 28% 26%, red, transparent)'],
    'in a comment' => ['/* margin-left: 4px; text-align: left */ color: red'],
]);

it('reads the styles a template carries itself', function () {
    expect(physicalDirectionInTemplateStyles('<style>.a { margin-left: 2px }</style>'))->toBe(['margin-left: 2px'])
        ->and(physicalDirectionInTemplateStyles('<p style="text-align: left">x</p>'))->toBe(['text-align: left'])
        ->and(physicalDirectionInTemplateStyles('<span x-bind:style="`left: ${x}px`"></span>'))->toBe(['left: ${x}px'])
        ->and(physicalDirectionInTemplateStyles('<div style="width: {{ $pct }}%; --rg-game-widest: 4px"></div>'))->toBe([]);
});

it('finds script that reads a horizontal position or hears the arrow keys', function (string $source) {
    expect(physicalDirectionInScript($source))->not->toBe([]);
})->with([
    "if (event.key === 'ArrowLeft') previous()",
    'const x = event.clientX - start',
    'el.style.left = `${x}px`',
    'list.scrollLeft += 100',
    '<div x-on:keydown.right="next()"></div>',
]);
