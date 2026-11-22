<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficBlockLevel;

/**
 * Reads campaigns' block lists as the network has them — what RateGuru put
 * there and what an administrator blocked in the network's own panel — and
 * never writes one.
 */
interface ReadsBlockLists
{
    /**
     * Each campaign's block list at a level, by the campaign's ID. A campaign
     * whose list can't be read for what it is — the network doesn't know it,
     * or it lists what to allow, not what to block — is left out.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<string>>
     *
     * @throws ConnectorException
     */
    public function blockLists(ConnectorSession $session, array $campaignIds, TrafficBlockLevel $level): array;
}
