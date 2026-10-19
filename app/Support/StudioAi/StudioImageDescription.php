<?php

namespace App\Support\StudioAi;

/**
 * What a photo shows, as the text generators are to read it: technical
 * context, never shown to visitors.
 *
 *  - summary — one or two sentences on what is in the photo;
 *  - subjects — the main people, animals and objects that are visible;
 *  - actions — what is visibly happening;
 *  - setting — the visible place or surroundings;
 *  - composition — framing, viewpoint, light and focus;
 *  - notable_details — small visible details worth knowing;
 *  - visible_text — text readable in the photo, as written;
 *  - uncertainties — what the photo leaves unclear.
 *
 * Every field is always there: an empty string or list says nothing of the
 * kind is visible. A description is made only from an answer that passes
 * every check below — exactly these fields, of these types, within these
 * lengths, a summary that says something — and an answer that fails one is
 * refused whole, never trimmed into shape.
 */
final readonly class StudioImageDescription
{
    public const SUMMARY_MAX_CHARS = 600;

    public const TEXT_MAX_CHARS = 400;

    public const LIST_MAX_ITEMS = 12;

    public const ITEM_MAX_CHARS = 200;

    /** The fields, in the order the schema and the editor list them. */
    public const FIELDS = ['summary', 'subjects', 'actions', 'setting', 'composition', 'notable_details', 'visible_text', 'uncertainties'];

    private const TEXT_FIELDS = ['setting', 'composition'];

    private const LIST_FIELDS = ['subjects', 'actions', 'notable_details', 'visible_text', 'uncertainties'];

    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $actions
     * @param  list<string>  $notableDetails
     * @param  list<string>  $visibleText
     * @param  list<string>  $uncertainties
     */
    private function __construct(
        public string $summary,
        public array $subjects,
        public array $actions,
        public string $setting,
        public string $composition,
        public array $notableDetails,
        public array $visibleText,
        public array $uncertainties,
    ) {}

    /**
     * The description these values make, or null when they are not one.
     *
     * @param  array<mixed>  $values  as decoded from the provider's structured output, or as stored
     */
    public static function from(array $values): ?self
    {
        $keys = array_keys($values);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            return null;
        }

        $summary = self::text($values['summary'], self::SUMMARY_MAX_CHARS);

        if ($summary === null || $summary === '') {
            return null;
        }

        $texts = [];

        foreach (self::TEXT_FIELDS as $field) {
            $texts[$field] = self::text($values[$field], self::TEXT_MAX_CHARS);

            if ($texts[$field] === null) {
                return null;
            }
        }

        $lists = [];

        foreach (self::LIST_FIELDS as $field) {
            $lists[$field] = self::items($values[$field]);

            if ($lists[$field] === null) {
                return null;
            }
        }

        return new self(
            $summary,
            $lists['subjects'],
            $lists['actions'],
            $texts['setting'],
            $texts['composition'],
            $lists['notable_details'],
            $lists['visible_text'],
            $lists['uncertainties'],
        );
    }

    /**
     * The JSON schema the provider's answer must follow, in strict mode:
     * exactly these fields, every one required, nothing more. The lengths
     * are asked for in the instructions and checked by from().
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'subjects' => $list,
                'actions' => $list,
                'setting' => ['type' => 'string'],
                'composition' => ['type' => 'string'],
                'notable_details' => $list,
                'visible_text' => $list,
                'uncertainties' => $list,
            ],
            'required' => self::FIELDS,
            'additionalProperties' => false,
        ];
    }

    /** @return array{summary: string, subjects: list<string>, actions: list<string>, setting: string, composition: string, notable_details: list<string>, visible_text: list<string>, uncertainties: list<string>} */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'subjects' => $this->subjects,
            'actions' => $this->actions,
            'setting' => $this->setting,
            'composition' => $this->composition,
            'notable_details' => $this->notableDetails,
            'visible_text' => $this->visibleText,
            'uncertainties' => $this->uncertainties,
        ];
    }

    /** A trimmed string within the limit, with no control characters; null when it is not one. */
    private static function text(mixed $value, int $maxChars): ?string
    {
        if (! is_string($value) || mb_check_encoding($value, 'UTF-8') === false) {
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > $maxChars || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** @return list<string>|null a list of non-empty strings within the limits; null when it is not one */
    private static function items(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::LIST_MAX_ITEMS) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            $text = self::text($item, self::ITEM_MAX_CHARS);

            if ($text === null || $text === '' || str_contains($text, "\n")) {
                return null;
            }

            $items[] = $text;
        }

        return $items;
    }
}
