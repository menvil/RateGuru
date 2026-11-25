<?php

namespace App\Support\Languages;

/**
 * How the feed and the threads put the viewer's language first, as Project settings hold it
 * (project_settings.language_ranking, read under DEFAULTS by ProjectSettingsManager). Every value is held to its
 * range here, so a hand-edited row cannot reach a query out of bounds.
 */
final readonly class LanguageRankingSettings
{
    /** The keys an administrator sets, with what an installation that never saved them runs on. */
    public const DEFAULTS = [
        // The switch for the whole feature: off, the feed and the threads keep their order of today.
        'enabled' => false,
        // How long a post in a guest's language counts as fresh.
        'guest_window_days' => 7,
        // A member's posts count as fresh since their previous visit, but never further back than this.
        'member_window_days' => 7,
        // Every so many places of New and Hot, a strong post in another language; 0 shows none.
        'hit_every' => 10,
        // How strong: among the top this-many percent of the week's posts by the votes and comments gathered.
        'hit_top_percent' => 5,
        // In Hot, a post in the viewer's language is lifted only above this percentile of the last two days'
        // hot scores; 0 lifts every one.
        'hot_floor_percentile' => 0,
        'comments_first' => true,
        'ai_checks' => true,
        'ai_checks_per_day' => 3000,
    ];

    /** The shortest window: posts since a member's visit of an hour ago are no window at all. */
    public const SHORTEST_WINDOW_DAYS = 1;

    public const LONGEST_WINDOW_DAYS = 30;

    public function __construct(
        public bool $enabled,
        public int $guestWindowDays,
        public int $memberWindowDays,
        public int $hitEvery,
        public int $hitTopPercent,
        public int $hotFloorPercentile,
        public bool $commentsFirst,
        public bool $aiChecks,
        public int $aiChecksPerDay,
    ) {}

    /**
     * @param  array<string, mixed>  $values  what the row holds, merged over DEFAULTS
     */
    public static function fromArray(array $values): self
    {
        $values = array_merge(self::DEFAULTS, $values);

        return new self(
            enabled: (bool) $values['enabled'],
            guestWindowDays: self::within($values['guest_window_days'], self::SHORTEST_WINDOW_DAYS, self::LONGEST_WINDOW_DAYS),
            memberWindowDays: self::within($values['member_window_days'], self::SHORTEST_WINDOW_DAYS, self::LONGEST_WINDOW_DAYS),
            // Every place a hit is no feed at all: from every second place on.
            hitEvery: ($hitEvery = self::within($values['hit_every'], 0, 50)) === 1 ? 2 : $hitEvery,
            hitTopPercent: self::within($values['hit_top_percent'], 1, 50),
            hotFloorPercentile: self::within($values['hot_floor_percentile'], 0, 90),
            commentsFirst: (bool) $values['comments_first'],
            aiChecks: (bool) $values['ai_checks'],
            aiChecksPerDay: self::within($values['ai_checks_per_day'], 0, 100000),
        );
    }

    /**
     * The settings as Project settings stores them, under DEFAULTS' keys.
     *
     * @return array{enabled: bool, guest_window_days: int, member_window_days: int, hit_every: int, hit_top_percent: int, hot_floor_percentile: int, comments_first: bool, ai_checks: bool, ai_checks_per_day: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'guest_window_days' => $this->guestWindowDays,
            'member_window_days' => $this->memberWindowDays,
            'hit_every' => $this->hitEvery,
            'hit_top_percent' => $this->hitTopPercent,
            'hot_floor_percentile' => $this->hotFloorPercentile,
            'comments_first' => $this->commentsFirst,
            'ai_checks' => $this->aiChecks,
            'ai_checks_per_day' => $this->aiChecksPerDay,
        ];
    }

    /** Whether the threads put the viewer's language first: only while the feature is on. */
    public function ranksComments(): bool
    {
        return $this->enabled && $this->commentsFirst;
    }

    private static function within(mixed $value, int $least, int $most): int
    {
        // Held to the range before it becomes a whole number: a huge float would overflow the cast.
        return is_numeric($value) ? (int) max($least, min($most, (float) $value)) : $least;
    }
}
