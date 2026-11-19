<?php

namespace App\Data\Games;

/**
 * Saved changes the players of a game don't see yet: what they are, named as
 * the editors name them and grouped by the flow, content set or game they
 * belong to; the version they were found against; the versions players play,
 * made before the changes; and the content set and flow a new version would
 * be published from to put them live, taking over the newest of those
 * versions' traffic.
 */
final readonly class UnpublishedChanges
{
    /** How many changes a summary names before it counts the rest. */
    public const NAMED = 3;

    /**
     * @param  list<string>  $versions  the labels of the versions in traffic the changes are missing from, oldest first
     * @param  string  $since  the label of the version the changes were found against
     * @param  list<array{title: string, changes: list<string>}>  $groups  the changes, by the flow, set or game they belong to
     */
    public function __construct(
        public array $versions,
        public int $setId,
        public int $flowId,
        public int $takeOverId,
        public string $since,
        public array $groups,
    ) {}

    /** @return list<string> every change, group after group */
    public function changes(): array
    {
        return array_merge(...array_map(fn (array $group): array => $group['changes'], $this->groups));
    }

    public function count(): int
    {
        return count($this->changes());
    }

    /** “2 changes since V2” */
    public function headline(): string
    {
        $count = $this->count();

        return $count.' '.($count === 1 ? 'change' : 'changes').' since '.$this->since;
    }

    /** Each change while there are three at most; else the first three “and 2 more”. */
    public function named(): string
    {
        $changes = $this->changes();
        $named = implode(', ', array_slice($changes, 0, self::NAMED));

        return count($changes) > self::NAMED ? $named.' and '.(count($changes) - self::NAMED).' more' : $named;
    }

    /** Whether some changes are only counted, so the full list is worth opening. */
    public function hasMore(): bool
    {
        return $this->count() > self::NAMED;
    }
}
