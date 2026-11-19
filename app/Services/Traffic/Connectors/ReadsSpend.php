<?php

namespace App\Services\Traffic\Connectors;

interface ReadsSpend
{
    /**
     * @param  list<string>  $campaignIds  the network's IDs of the campaigns RateGuru saw clicks of
     *
     * @throws ConnectorException
     */
    public function spendForDay(ConnectorSession $session, string $networkDay, array $campaignIds): SpendDayReport;
}
