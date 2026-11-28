<?php

namespace App\Support\Traffic\Capture;

/**
 * What a landing page's script measured of its visitor (traffic.js), as the
 * request carried it — every number capped and every word one of a few, so a
 * forged report can't store more than a real one:
 *
 * - visibility: whether the page was on screen when the script started, the
 *   milliseconds until it first was, and how long it was on screen in all;
 * - input: the milliseconds from first being on screen to the first touch,
 *   click, key or wheel the browser itself made (isTrusted), its kind, how
 *   many such inputs, how many made by a script instead, and whether one came
 *   while the page was hidden;
 * - the browser: navigator.webdriver, the automation tools it shows, BotD's
 *   verdict, whether the page is inside another site's frame, the graphics
 *   class (hw, software or none — never the renderer's name), its time zone
 *   and offset, its first languages, navigator.platform, the screen and
 *   window sizes, the pixel ratio, touch points, cores and memory, and how
 *   many plugins it lists.
 */
final readonly class PresenceFacts
{
    public const array INPUT_KINDS = ['pointer', 'touch', 'key', 'wheel'];

    public const array GRAPHICS = ['hw', 'software', 'none'];

    private const int MOST_MS = 3_600_000;

    private const int MOST_PIXELS = 20_000;

    /** @param  array<string, mixed>  $facts  the facts as normalize() gives them */
    private function __construct(public array $facts) {}

    /** @param  array<string, mixed>  $input */
    public static function from(array $input): self
    {
        $int = fn (string $key, int $most): ?int => isset($input[$key]) && is_numeric($input[$key]) ? max(0, min($most, (int) $input[$key])) : null;
        $flag = fn (string $key): bool => in_array($input[$key] ?? null, [1, '1', true, 'true'], true);
        $word = fn (string $key, array $words): ?string => in_array($input[$key] ?? null, $words, true) ? (string) $input[$key] : null;
        $text = fn (string $key, int $most): ?string => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? mb_substr(trim($input[$key]), 0, $most) : null;

        return new self(array_filter([
            'visible_at_start' => $flag('vis0'),
            'to_visible_ms' => $int('tvis', self::MOST_MS),
            'visible_ms' => $int('visms', self::MOST_MS),
            'to_input_ms' => $int('tin', self::MOST_MS),
            'input_kind' => $word('inkind', self::INPUT_KINDS),
            'inputs' => $int('ins', 1000),
            'untrusted' => $int('unt', 1000),
            'hidden_input' => $flag('hin'),
            'webdriver' => $flag('wd'),
            'automation' => $int('auto', 255),
            'botd' => $text('botd', 32),
            'framed' => $flag('frm'),
            'graphics' => $word('gl', self::GRAPHICS),
            'time_zone' => $text('tz', 64),
            'offset_minutes' => isset($input['tzo']) && is_numeric($input['tzo']) ? max(-900, min(900, (int) $input['tzo'])) : null,
            'languages' => $text('langs', 40),
            'platform' => $text('plat', 32),
            'screen_width' => $int('sw', self::MOST_PIXELS),
            'screen_height' => $int('sh', self::MOST_PIXELS),
            'window_width' => $int('ww', self::MOST_PIXELS),
            'window_height' => $int('wh', self::MOST_PIXELS),
            'pixel_ratio' => isset($input['dpr']) && is_numeric($input['dpr']) ? round(max(0.0, min(10.0, (float) $input['dpr'])), 2) : null,
            'touch_points' => $int('mtp', 64),
            'cores' => $int('hc', 1024),
            'memory_gb' => $int('dm', 1024),
            'plugins' => $int('plg', 100),
        ], fn (mixed $value): bool => $value !== null && $value !== false));
    }

    /** Whether the page was ever on screen. */
    public function seen(): bool
    {
        return ($this->facts['visible_at_start'] ?? false) === true || isset($this->facts['to_visible_ms']);
    }

    /** Whether the visitor touched, clicked, typed or scrolled it — the browser's own input, not a script's. */
    public function engaged(): bool
    {
        return isset($this->facts['to_input_ms']) || (int) ($this->facts['inputs'] ?? 0) > 0;
    }

    /**
     * A later report of the same page over an earlier one: its counts and
     * times where they grew, its flags where they turned on, the earlier
     * report's facts of the browser kept.
     *
     * @param  array<string, mixed>  $earlier
     * @return array<string, mixed>
     */
    public function over(array $earlier): array
    {
        $merged = [...$this->facts, ...$earlier];

        foreach (['visible_ms', 'inputs', 'untrusted'] as $count) {
            if (isset($this->facts[$count]) || isset($earlier[$count])) {
                $merged[$count] = max((int) ($this->facts[$count] ?? 0), (int) ($earlier[$count] ?? 0));
            }
        }

        foreach (['to_visible_ms', 'to_input_ms', 'input_kind'] as $first) {
            $merged[$first] = $earlier[$first] ?? $this->facts[$first] ?? null;
        }

        foreach (['hidden_input', 'webdriver', 'framed'] as $flag) {
            $merged[$flag] = ($earlier[$flag] ?? false) === true || ($this->facts[$flag] ?? false) === true;
        }

        return array_filter($merged, fn (mixed $value): bool => $value !== null && $value !== false);
    }
}
