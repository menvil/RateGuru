<?php

namespace App\Support\StudioAi;

use App\Support\Posts\PostContentRules;

/**
 * One batch of post text suggestions, as the editor offers them: titles and
 * descriptions, exactly as many of each as were asked for.
 *
 * A batch is made only from an answer that passes every check below —
 * exactly these two fields, lists of exactly that many strings, each valid
 * UTF-8 with no control characters, within a post's own bounds
 * (PostContentRules: titles 3 to 120 characters on one line, descriptions
 * up to 2,000), and no two in a list the same or nearly so. An answer that
 * fails one is refused whole: nothing is trimmed into shape, dropped or
 * made up to fill a gap.
 */
final readonly class StudioPostTextSuggestions
{
    /** The length a description is asked to keep to; the post's own limit is the hard one. */
    public const DESCRIPTION_TARGET_CHARS = 160;

    /**
     * How alike two texts in a list may be before they count as one: the
     * share of their words they have in common. The same words in any order,
     * with any case or punctuation, are always one.
     */
    private const NEAR_IDENTICAL = 0.9;

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $descriptions
     */
    private function __construct(
        public array $titles,
        public array $descriptions,
    ) {}

    /**
     * The batch these values make, or null when they are not one.
     *
     * @param  array<mixed>  $values  as decoded from the provider's structured output, or as stored
     */
    public static function from(array $values, int $count): ?self
    {
        $keys = array_keys($values);
        sort($keys);

        if ($keys !== ['descriptions', 'titles'] || $count < 1) {
            return null;
        }

        $titles = self::texts($values['titles'], $count, PostContentRules::TITLE_MIN_LENGTH, PostContentRules::TITLE_LIMIT, multiline: false);
        $descriptions = self::texts($values['descriptions'], $count, 1, PostContentRules::DESCRIPTION_LIMIT, multiline: true);

        return $titles !== null && $descriptions !== null ? new self($titles, $descriptions) : null;
    }

    /**
     * The JSON schema the provider's answer must follow, in strict mode:
     * exactly these fields, both required, nothing more. How many texts each
     * holds and how long they are is asked for in the instructions and
     * checked by from().
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $texts = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'titles' => $texts,
                'descriptions' => $texts,
            ],
            'required' => ['titles', 'descriptions'],
            'additionalProperties' => false,
        ];
    }

    /** @return array{titles: list<string>, descriptions: list<string>} */
    public function toArray(): array
    {
        return ['titles' => $this->titles, 'descriptions' => $this->descriptions];
    }

    /** @return list<string>|null exactly $count distinct texts within the bounds; null when the value is not that */
    private static function texts(mixed $value, int $count, int $minChars, int $maxChars, bool $multiline): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== $count) {
            return null;
        }

        // Line feeds alone are allowed, and only in a description.
        $controls = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u' : '/[\x00-\x1F\x7F\x{80}-\x{9F}]/u';
        $texts = [];
        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item) || mb_check_encoding($item, 'UTF-8') === false) {
                return null;
            }

            $text = trim($item);
            $length = mb_strlen($text);

            if ($length < $minChars || $length > $maxChars || preg_match($controls, $text) !== 0) {
                return null;
            }

            $words = self::words($text);

            foreach ($seen as $other) {
                if (self::alike($words, $other)) {
                    return null;
                }
            }

            $seen[] = $words;
            $texts[] = $text;
        }

        return $texts;
    }

    /** @return list<string> the text's words, lowercased, in order */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * @param  list<string>  $one
     * @param  list<string>  $other
     */
    private static function alike(array $one, array $other): bool
    {
        $one = array_values(array_unique($one));
        $other = array_values(array_unique($other));
        $union = count(array_unique([...$one, ...$other]));

        return $union === 0 || count(array_intersect($one, $other)) / $union >= self::NEAR_IDENTICAL;
    }
}
