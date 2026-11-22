<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficConnectorFailure;
use RuntimeException;

/**
 * A call to an ad network that failed, by reason only: no text from the
 * network, and never the HTTP client's exception, whose message carries the
 * address — with PopAds' key in it. A call about one campaign of a batch —
 * reading its block list among the others' — names it by the network's ID.
 */
final class ConnectorException extends RuntimeException
{
    public function __construct(
        public readonly TrafficConnectorFailure $failure,
        public readonly ?int $httpStatus = null,
        public readonly ?string $campaignId = null,
    ) {
        parent::__construct($failure->message());
    }

    /** The same failure, naming the campaign, by the network's ID, the call was about. */
    public function forCampaign(string $campaignId): self
    {
        return new self($this->failure, $this->httpStatus, $campaignId);
    }
}
