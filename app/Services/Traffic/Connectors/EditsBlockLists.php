<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficBlockLevel;

/** Changes campaigns' block lists; whatever changes a list reads it too. */
interface EditsBlockLists extends ReadsBlockLists
{
    /** @return list<TrafficBlockLevel> what the network's API can block */
    public function blockLevels(): array;

    /**
     * Adds to and removes from a campaign's block list — read first, written
     * whole, read back — so doing it twice changes nothing.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     *
     * @throws ConnectorException
     */
    public function applyBlockDelta(ConnectorSession $session, string $campaignId, TrafficBlockLevel $level, array $add, array $remove): BlockDeltaResult;
}
