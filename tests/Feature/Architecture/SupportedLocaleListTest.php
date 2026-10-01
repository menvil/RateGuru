<?php

use App\Support\Locale\LocaleManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * config/locales.php is the only place that lists the languages we offer.
 *
 * A copy of the list anywhere else is a place that silently ignores the next
 * language: it keeps passing while the new language is never exercised, and
 * nobody is told to update it. Everything that means "every language" reads
 * supportedLocales(), translatedLocales() or config('locales.supported').
 *
 * A copy takes three shapes, all refused when two or more supported languages
 * sit in the same array: the codes as a list; rows of the registry keyed by
 * code — entries carrying the `label` / `native` / `flag` a language is
 * declared with, or its name or flag alone, which is what a test overriding config('locales.supported')
 * with its own set writes; and codes keyed to a bare `true` or `false`, which
 * is a list wearing keys (a hand-written "enabled languages" map).
 *
 * What stays allowed is a single language — a test about Russian plurals is
 * about Russian — and content keyed by language: an expected Russian sentence
 * beside an expected Bulgarian one, or a preset's name in each language, where
 * `null` means "nothing in this language". Those are data in a language, not
 * the list of languages.
 */

/**
 * Every array literal in a PHP source that writes out two or more supported
 * languages as a set, as the codes it holds.
 *
 * Read from tokens rather than matched as text, so a bracket inside a string
 * or a comment cannot unbalance anything and "in the same array" means that.
 *
 * @param  array<string, array{label: string, native: string}>  $registry
 * @return list<list<string>>
 */
function handWrittenLocaleSets(string $source, array $registry): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $text = fn (int $i): ?string => isset($tokens[$i]) ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : null;
    $literal = fn (int $i): ?string => isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING
        ? substr($tokens[$i][1], 1, -1)
        : null;
    $opensArray = fn (int $i): bool => $text($i) === '[' || ($text($i) === '(' && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_ARRAY);
    $endsElement = fn (int $i): bool => in_array($text($i), [',', ']', ')'], true);

    $sets = [];
    $stack = [];

    foreach (array_keys($tokens) as $i) {
        $token = $text($i);

        if ($opensArray($i)) {
            $parent = array_key_last($stack);
            $arrow = $text($i) === '[' ? $i - 1 : $i - 2;
            $stack[] = [
                'array' => true,
                'codes' => [],
                'row' => false,
                // A nested array that is the value of a language key may turn
                // out to be that language's registry row.
                'value_of' => $parent !== null && $text($arrow) === '=>' ? ($stack[$parent]['key'] ?? null) : null,
                'key' => null,
            ];

            continue;
        }

        if (in_array($token, ['(', '{'], true) || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true))) {
            $stack[] = ['array' => false];

            continue;
        }

        if (in_array($token, [']', ')', '}'], true)) {
            $frame = array_pop($stack) ?? ['array' => false];

            if ($frame['array']) {
                $codes = array_values(array_unique($frame['codes']));

                if (count($codes) >= 2) {
                    $sets[] = $codes;
                }

                if ($frame['row'] && $frame['value_of'] !== null && $stack !== []) {
                    $stack[array_key_last($stack)]['codes'][] = $frame['value_of'];
                }
            }

            continue;
        }

        $top = array_key_last($stack);
        $string = $literal($i);

        if ($top === null || ! $stack[$top]['array'] || $string === null || ! in_array($text($i - 1), ['[', '(', ','], true)) {
            continue;
        }

        if ($text($i + 1) === '=>') {
            $stack[$top]['key'] = isset($registry[$string]) ? $string : null;

            if (in_array($string, ['label', 'native', 'flag'], true)) {
                $stack[$top]['row'] = true;
            }

            // `'ru' => 'Русский'` is the row reduced to the language's name or flag;
            // `'ru' => true` is the code with nothing about the language at all.
            $value = $literal($i + 2);
            $flag = in_array(strtolower((string) $text($i + 2)), ['true', 'false'], true);

            if (isset($registry[$string]) && $endsElement($i + 3) && ($flag || ($value !== null && in_array($value, [$registry[$string]['label'], $registry[$string]['native'], $registry[$string]['flag']], true)))) {
                $stack[$top]['codes'][] = $string;
            }
        } elseif (isset($registry[$string]) && $endsElement($i + 1)) {
            $stack[$top]['codes'][] = $string;
        }
    }

    return $sets;
}

/** A registry row written the way a test overriding the config writes it. */
function registryRowSource(string $locale): string
{
    $info = config("locales.supported.{$locale}");

    return var_export($locale, true).' => [\'label\' => '.var_export($info['label'], true).', \'native\' => '.var_export($info['native'], true).', \'flag\' => '.var_export($info['flag'], true).']';
}

it('reads the supported languages from config/locales.php alone', function () {
    $configured = array_keys(config('locales.supported'));

    expect(supportedLocales())->toBe($configured)
        ->and(array_keys(app(LocaleManager::class)->supported()))->toBe($configured)
        ->and(translatedLocales())->toBe(array_values(array_diff($configured, ['en'])))
        ->and($configured)->not->toContain(unsupportedLocale());
});

it('never spells out a set of supported languages', function () {
    $registry = config('locales.supported');

    $files = Finder::create()
        ->files()
        ->in([base_path('tests'), app_path(), config_path(), resource_path('views'), base_path('routes'), database_path()])
        ->name('*.php')
        ->notPath('Browser/Screenshots');

    $problems = [];

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if ($relative === 'config/locales.php') {
            continue;
        }

        $source = File::get($file->getPathname());
        $source = str_ends_with($relative, '.blade.php') ? Blade::compileString($source) : $source;

        foreach (handWrittenLocaleSets($source, $registry) as $codes) {
            $problems[] = "{$relative}: ".implode(', ', $codes);
        }
    }

    expect($problems)->toBe([], "supported languages spelled out instead of read from config/locales.php:\n".implode("\n", $problems));
});

it('recognises every shape a copied set of languages takes', function () {
    $registry = config('locales.supported');
    [$first, $second] = supportedLocales();
    $rows = implode(",\n        ", array_map(registryRowSource(...), supportedLocales()));

    $copies = [
        // The override RecipientLocaleTest used to carry, rebuilt from today's config.
        'registry override' => "<?php\nconfig()->set('locales.supported', [\n        {$rows},\n    ]);",
        'registry as array()' => '<?php $supported = '.var_export($registry, true).';',
        'part of the registry' => '<?php $x = ['.registryRowSource($first).', '.registryRowSource($second).'];',
        'names by code' => "<?php \$x = ['{$first}' => '{$registry[$first]['native']}', '{$second}' => '{$registry[$second]['label']}'];",
        'flags by code' => "<?php \$x = ['{$first}' => '{$registry[$first]['flag']}', '{$second}' => '{$registry[$second]['flag']}'];",
        'list of codes' => "<?php foreach (['{$first}', '{$second}'] as \$locale) {}",
        'codes keyed to a flag' => "<?php \$enabled = ['{$first}' => true, '{$second}' => false];",
        'dataset' => "<?php it('x', fn () => null)->with(['{$first}', \"{$second}\"]);",
    ];

    foreach ($copies as $shape => $source) {
        expect(handWrittenLocaleSets($source, $registry))->not->toBe([], "missed: {$shape}");
    }

    expect(handWrittenLocaleSets($copies['registry override'], $registry))->toBe([supportedLocales()]);
});

it('leaves a single language and language-specific content alone', function () {
    $registry = config('locales.supported');
    [$first, $second] = supportedLocales();

    $allowed = [
        'one locale in a test' => "<?php app()->setLocale('{$second}');",
        'withdrawing one locale' => "<?php config()->set('locales.supported', [".registryRowSource($first).']);',
        'expected output per language' => "<?php it('x', fn () => null)->with(['{$first}' => ['{$first}', '1 day left'], '{$second}' => ['{$second}', 'other words']]);",
        'content keyed by language' => "<?php \$name = ['{$first}' => 'Food', '{$second}' => 'Something else'];",
        'nothing in each language' => "<?php \$description = ['{$first}' => null, '{$second}' => null];",
        'one locale per row' => "<?php it('x', fn () => null)->with([['{$first}', 'a'], ['{$second}', 'b']]);",
        'codes as call arguments' => "<?php translate('{$first}', '{$second}');",
        'codes in a comment' => "<?php // ['{$first}', '{$second}']",
        'codes in one string' => "<?php \$pattern = \"['{$first}', '{$second}']\";",
    ];

    foreach ($allowed as $shape => $source) {
        expect(handWrittenLocaleSets($source, $registry))->toBe([], "flagged: {$shape}");
    }
});
