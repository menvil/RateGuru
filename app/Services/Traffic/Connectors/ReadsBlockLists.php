<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficBlockLevel;

/**
 * Reads campaigns' block lists as the network has them — what RateGuru put
 * there and what an administrator blocked in the network's own panel — and
 * never writes one: the sync's read of the lists is given this interface
 * alone, so it can't change a list.
 */
interface ReadsBlockLists
{
    /** @return list<TrafficBlockLevel> what the network's API can block */
    public function blockLevels(): array;

    /**
     * Each campaign's block list at a level, by the campaign's ID. A campaign
     * whose list can't be read for what it is — the network doesn't know it,
     * or it lists what to allow, not what to block — is left out. So is one
     * whose list couldn't be read, the first such failure given back with the
     * lists read: one campaign's answer never costs the others theirs, and
     * the network slow, down or busy leaves the rest for the next read. A key
     * the network refused is thrown.
     *
     * @param  list<string>  $campaignIds
     *
     * @throws ConnectorException
     */
    public function blockLists(ConnectorSession $session, array $campaignIds, TrafficBlockLevel $level): BlockListsRead;
}
