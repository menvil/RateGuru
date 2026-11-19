<?php

namespace App\Support\Games\Traffic;

/**
 * Whom a game's traffic period is for: everyone — the game's general split —
 * or the paid clicks of one traffic source, or of one of that source's
 * campaigns (docs/architecture/games.md, "Splits for a source"). An arrival
 * has a scope too: a paid click that brought the visitor to the game is its
 * campaign's, else its source's; any other arrival is everyone's. The
 * arrival falls under its own scope, then its source's, then everyone's, and
 * the most specific of those a game has a split for decides.
 */
final readonly class TrafficScope
{
    private function __construct(
        public ?int $sourceId,
        public ?int $campaignId,
    ) {}

    public static function everyone(): self
    {
        return new self(null, null);
    }

    public static function source(int $sourceId): self
    {
        return new self($sourceId, null);
    }

    public static function campaign(int $sourceId, int $campaignId): self
    {
        return new self($sourceId, $campaignId);
    }

    /** The scope a period's own columns name; a campaign without a source names none. */
    public static function of(?int $sourceId, ?int $campaignId): self
    {
        return match (true) {
            $sourceId === null => self::everyone(),
            $campaignId === null => self::source($sourceId),
            default => self::campaign($sourceId, $campaignId),
        };
    }

    /**
     * The scope of the visit a page or an attempt is credited to: a paid
     * click's — its campaign's, or its source's — when the click brought the
     * visitor to this game; everyone's for any other visit, an unpaid one,
     * or none.
     */
    public static function ofVisit(int $gameId, ?int $visitGameId, ?int $sourceId, ?int $campaignId): self
    {
        return $visitGameId === $gameId ? self::of($sourceId, $campaignId) : self::everyone();
    }

    public function isEveryone(): bool
    {
        return $this->sourceId === null;
    }

    public function isCampaign(): bool
    {
        return $this->campaignId !== null;
    }

    /** What the period's open_scope holds while it is open: one open period per game, audience and scope. */
    public function key(): string
    {
        return match (true) {
            $this->sourceId === null => 'everyone',
            $this->campaignId === null => 'source:'.$this->sourceId,
            default => 'campaign:'.$this->campaignId,
        };
    }

    /**
     * The scopes an arrival of this scope falls under, the most specific
     * first: its campaign's, its source's, everyone's.
     *
     * @return list<self>
     */
    public function fallbacks(): array
    {
        return match (true) {
            $this->sourceId === null => [self::everyone()],
            $this->campaignId === null => [$this, self::everyone()],
            default => [$this, self::source($this->sourceId), self::everyone()],
        };
    }

    public function equals(self $other): bool
    {
        return $this->sourceId === $other->sourceId && $this->campaignId === $other->campaignId;
    }
}
