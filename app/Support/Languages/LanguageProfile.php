<?php

namespace App\Support\Languages;

/**
 * The language a post is written in, worked out from what tells it: the post's own text, its author's language,
 * its comments and — weakly — the languages of those who voted on it. Each signal adds weight to a language; the
 * post is in the language holding at least half of it, or in none.
 *
 * The weights were tried on a simulated site of 28 languages before they were chosen
 * (docs/architecture/language-ranking.md): together the signals named a post's language about 98% of the time a
 * day after it came out, the text alone about 80%. The votes are capped so that a popular post voted on by people
 * of every language keeps its own.
 */
final class LanguageProfile
{
    public const TEXT_WEIGHT = 3.0;

    public const AUTHOR_WEIGHT = 2.0;

    public const COMMENT_WEIGHT = 1.0;

    /** The share of a comment's weight its writer's language gets when the comment's text tells no language. */
    public const COMMENTER_SHARE = 0.5;

    public const VOTE_WEIGHT = 0.3;

    /** All the votes together count for no more than the author. */
    public const VOTES_CAP = 2.0;

    /** A post is in the language holding at least this share of the weight. */
    public const MAJORITY = 0.5;

    /** @var array<string, float> */
    private array $weights = [];

    /** @var array<string, float> */
    private array $votes = [];

    /**
     * The post's title and description, as a detector read them: the language it named and how much that counts.
     */
    public function text(?string $language, float $weight): self
    {
        if ($language !== null && $weight > 0) {
            $this->add($language, self::TEXT_WEIGHT * min(1.0, $weight));
        }

        return $this;
    }

    public function author(?string $locale): self
    {
        if ($locale !== null) {
            $this->add($locale, self::AUTHOR_WEIGHT);
        }

        return $this;
    }

    /**
     * A visible comment: its text's language as far as a detector could tell it, and the rest of its weight — all
     * of it for a comment of only emoji or names — at half to the language of the one who wrote it.
     */
    public function comment(?string $language, float $weight, ?string $commenterLocale): self
    {
        $weight = $language === null ? 0.0 : max(0.0, min(1.0, $weight));

        if ($language !== null && $weight > 0) {
            $this->add($language, self::COMMENT_WEIGHT * $weight);
        }

        if ($commenterLocale !== null && $weight < 1.0) {
            $this->add($commenterLocale, self::COMMENT_WEIGHT * self::COMMENTER_SHARE * (1.0 - $weight));
        }

        return $this;
    }

    public function vote(?string $voterLocale): self
    {
        if ($voterLocale !== null) {
            $this->votes[$voterLocale] = ($this->votes[$voterLocale] ?? 0.0) + self::VOTE_WEIGHT;
        }

        return $this;
    }

    /** The language holding at least half the weight, or null when none does or nothing told any. */
    public function language(): ?string
    {
        [$language, $share] = $this->leader();

        return self::holdsMajority($share) ? $language : null;
    }

    /** The share of the weight the post's language holds, or null when it has none. */
    public function share(): ?float
    {
        [, $share] = $this->leader();

        return self::holdsMajority($share) ? round($share, 3) : null;
    }

    /** @return array{0: string|null, 1: float} */
    private function leader(): array
    {
        $totals = $this->weights;
        $votes = array_sum($this->votes);
        $scale = $votes > self::VOTES_CAP ? self::VOTES_CAP / $votes : 1.0;

        foreach ($this->votes as $language => $weight) {
            $totals[$language] = ($totals[$language] ?? 0.0) + $weight * $scale;
        }

        $sum = array_sum($totals);

        if ($sum <= 0) {
            return [null, 0.0];
        }

        ksort($totals);
        $language = array_keys($totals, max($totals), true)[0];

        return [(string) $language, $totals[$language] / $sum];
    }

    /**
     * Half or more — exactly half included. Rounded to nine places first, so the sums and the votes' scaling cannot
     * leave an exact half a hair short; the weights are tenths, so nothing truly below half rounds up to it.
     */
    private static function holdsMajority(float $share): bool
    {
        return round($share, 9) >= self::MAJORITY;
    }

    private function add(string $language, float $weight): void
    {
        $this->weights[$language] = ($this->weights[$language] ?? 0.0) + $weight;
    }
}
