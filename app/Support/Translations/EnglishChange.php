<?php

namespace App\Support\Translations;

/**
 * What changed in a text's English since a translation was made from it, as
 * Translation Center shows an outdated translation: the sentences that changed,
 * each whole with the words taken out and put in marked, so whoever updates
 * the translation sees what to change in context rather than one word alone.
 * A sentence added or removed whole is marked whole.
 *
 * A long text — more than two sentences either way — says so: a change can
 * alter the sentences around it, so the whole translation is worth reading
 * again, and only the first MOST_SHOWN changed sentences are listed. Pure: no
 * config, no clock, no I/O.
 */
final readonly class EnglishChange
{
    /** How many changed sentences are listed; the rest are counted. */
    public const int MOST_SHOWN = 6;

    /** Above this many cells, a comparison is not worked out word by word: the sentences are marked whole. */
    private const int MOST_CELLS = 250_000;

    /**
     * @param  list<list<array{text: string, kind: 'same'|'removed'|'added'}>>  $sentences  the changed sentences listed, each as its parts
     * @param  int  $changed  how many sentences changed, the unlisted included
     * @param  bool  $long  whether the text is long enough to read whole again
     */
    private function __construct(
        public array $sentences,
        public int $changed,
        public bool $long,
    ) {}

    public static function between(string $before, string $after): self
    {
        $old = self::sentences($before);
        $new = self::sentences($after);
        $changed = [];
        $removed = [];
        $added = [];

        // Sentence by sentence; a run of changed sentences between unchanged ones pairs them up in order.
        foreach ([...self::diff($old, $new), ['same', '']] as [$kind, $sentence]) {
            if ($kind === 'removed') {
                $removed[] = $sentence;

                continue;
            }

            if ($kind === 'added') {
                $added[] = $sentence;

                continue;
            }

            foreach (array_keys($removed + $added) as $index) {
                $parts = match (true) {
                    ! isset($added[$index]) => [['text' => $removed[$index], 'kind' => 'removed']],
                    ! isset($removed[$index]) => [['text' => $added[$index], 'kind' => 'added']],
                    default => self::words($removed[$index], $added[$index]),
                };

                // A sentence that reads the same on both sides — paired up where the texts were too long to compare
                // sentence by sentence — did not change.
                if (array_filter($parts, fn (array $part): bool => $part['kind'] !== 'same') !== []) {
                    $changed[] = $parts;
                }
            }

            $removed = [];
            $added = [];
        }

        return new self(array_slice($changed, 0, self::MOST_SHOWN), count($changed), max(count($old), count($new)) > 2);
    }

    /** How many changed sentences are not listed. */
    public function unlisted(): int
    {
        return $this->changed - count($this->sentences);
    }

    /**
     * A text's sentences, each trimmed: split after a full stop, a question or
     * an exclamation mark and the space after it, and at every line break.
     *
     * @return list<string>
     */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\R+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), fn (string $part): bool => $part !== ''));
    }

    /**
     * One changed sentence word by word: its words as they stand, the ones
     * taken out and the ones put in, neighbouring parts of a kind joined.
     *
     * @return list<array{text: string, kind: 'same'|'removed'|'added'}>
     */
    private static function words(string $before, string $after): array
    {
        preg_match_all('/\s+|[^\s]+/u', $before, $old);
        preg_match_all('/\s+|[^\s]+/u', $after, $new);

        if (count($old[0]) * count($new[0]) > self::MOST_CELLS) {
            return [['text' => $before, 'kind' => 'removed'], ['text' => ' ', 'kind' => 'same'], ['text' => $after, 'kind' => 'added']];
        }

        $parts = [];

        foreach (self::diff($old[0], $new[0]) as [$kind, $text]) {
            $last = array_key_last($parts);

            if ($last !== null && $parts[$last]['kind'] === $kind) {
                $parts[$last]['text'] .= $text;
            } else {
                $parts[] = ['text' => $text, 'kind' => $kind];
            }
        }

        return $parts;
    }

    /**
     * The longest common subsequence of two lists, walked into what stayed,
     * what was taken out and what was put in — taken out before put in where
     * both happen at one place. Lists too long to compare cell by cell are
     * taken out and put in whole.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: 'same'|'removed'|'added', 1: string}>
     */
    private static function diff(array $old, array $new): array
    {
        // What both start and end with stays: only what lies between is compared, so one edit in a long text
        // compares a few items rather than the whole text.
        $start = 0;

        while ($start < count($old) && $start < count($new) && $old[$start] === $new[$start]) {
            $start++;
        }

        $end = 0;

        while ($end < count($old) - $start && $end < count($new) - $start && $old[count($old) - 1 - $end] === $new[count($new) - 1 - $end]) {
            $end++;
        }

        $same = fn (array $items): array => array_map(fn (string $item): array => ['same', $item], $items);

        return [
            ...$same(array_slice($old, 0, $start)),
            ...self::compared(array_slice($old, $start, count($old) - $start - $end), array_slice($new, $start, count($new) - $start - $end)),
            ...$same(array_slice($old, count($old) - $end)),
        ];
    }

    /**
     * The middle of two lists compared item by item; too long to compare cell
     * by cell, taken out and put in whole.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: 'same'|'removed'|'added', 1: string}>
     */
    private static function compared(array $old, array $new): array
    {
        $rows = count($old);
        $columns = count($new);

        if ($rows * $columns > self::MOST_CELLS) {
            return [...array_map(fn (string $item): array => ['removed', $item], $old), ...array_map(fn (string $item): array => ['added', $item], $new)];
        }

        // $lengths[$i][$j]: the longest common subsequence of $old from $i and $new from $j.
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $steps = [];
        $i = 0;
        $j = 0;

        while ($i < $rows || $j < $columns) {
            if ($i < $rows && $j < $columns && $old[$i] === $new[$j]) {
                $steps[] = ['same', $old[$i]];
                $i++;
                $j++;
            } elseif ($j >= $columns || ($i < $rows && $lengths[$i + 1][$j] >= $lengths[$i][$j + 1])) {
                $steps[] = ['removed', $old[$i]];
                $i++;
            } else {
                $steps[] = ['added', $new[$j]];
                $j++;
            }
        }

        return $steps;
    }
}
