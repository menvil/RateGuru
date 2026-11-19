<?php

namespace App\Services\Traffic\Connectors;

/**
 * Code written for one ad network's API (docs/architecture/traffic.md,
 * "Connectors"). Every connector checks a key; what else it can do is the
 * interfaces it implements — reading spend, reading campaigns, editing
 * block lists. None ever changes a bid or a budget or touches money.
 */
interface TrafficNetworkConnector
{
    /** @throws ConnectorException */
    public function verify(ConnectorSession $session): ConnectorAccount;
}
