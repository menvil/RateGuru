<?php

namespace App\Data\Games;

/**
 * Saved changes the players of a game don't see yet: the versions they play,
 * made before the changes, and the content set and flow a new version would
 * be published from to put them live, taking over the newest of those
 * versions' traffic.
 */
final readonly class UnpublishedChanges
{
    /** @param  list<string>  $versions  the labels of the versions in traffic the changes are missing from, oldest first */
    public function __construct(
        public array $versions,
        public int $setId,
        public int $flowId,
        public int $takeOverId,
    ) {}
}
