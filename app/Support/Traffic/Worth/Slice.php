<?php

namespace App\Support\Traffic\Worth;

/**
 * A slice of the traffic a chance is worked out for: a game, a source (or,
 * for unpaid traffic, its channel), a device and a country group — any of
 * them left open. Its key reads `g12|s3|d:mobile|k:t1`; the whole site is
 * `*`. Its parent leaves out the last dimension it names, in the order game,
 * source, device, group, so a slice with few accounts leans on the one above.
 */
final readonly class Slice
{
    public function __construct(
        public ?int $gameId = null,
        public ?int $sourceId = null,
        public ?string $channel = null,
        public ?string $device = null,
        public ?string $group = null,
    ) {}

    public static function site(): self
    {
        return new self;
    }

    public function key(): string
    {
        $parts = array_filter([
            $this->gameId !== null ? 'g'.$this->gameId : null,
            $this->sourceId !== null ? 's'.$this->sourceId : ($this->channel !== null ? 'c:'.$this->channel : null),
            $this->device !== null ? 'd:'.$this->device : null,
            $this->group !== null ? 'k:'.$this->group : null,
        ]);

        return $parts === [] ? '*' : implode('|', $parts);
    }

    public function parent(): ?self
    {
        return match (true) {
            $this->group !== null => new self($this->gameId, $this->sourceId, $this->channel, $this->device, null),
            $this->device !== null => new self($this->gameId, $this->sourceId, $this->channel, null, null),
            $this->sourceId !== null || $this->channel !== null => new self($this->gameId, null, null, null, null),
            $this->gameId !== null => self::site(),
            default => null,
        };
    }

    /** @return list<self> this slice and every one above it, up to the whole site */
    public function chain(): array
    {
        $chain = [];

        for ($slice = $this; $slice !== null; $slice = $slice->parent()) {
            $chain[] = $slice;
        }

        return $chain;
    }

    /**
     * The 16 slices a visit counts in: each of its four dimensions named or
     * left open. A visit with no game counts only in the slices without one.
     *
     * @return list<self>
     */
    public function subsets(): array
    {
        $subsets = [];

        foreach ([true, false] as $game) {
            foreach ([true, false] as $source) {
                foreach ([true, false] as $device) {
                    foreach ([true, false] as $group) {
                        if ($game && $this->gameId === null) {
                            continue;
                        }

                        $subsets[] = new self(
                            $game ? $this->gameId : null,
                            $source ? $this->sourceId : null,
                            $source ? $this->channel : null,
                            $device ? $this->device : null,
                            $group ? $this->group : null,
                        );
                    }
                }
            }
        }

        return $subsets;
    }
}
