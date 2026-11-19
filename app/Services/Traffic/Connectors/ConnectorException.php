<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficConnectorFailure;
use RuntimeException;

/**
 * A call to an ad network that failed, by reason only: no text from the
 * network, and never the HTTP client's exception, whose message carries the
 * address — with PopAds' key in it.
 */
final class ConnectorException extends RuntimeException
{
    public function __construct(public readonly TrafficConnectorFailure $failure, public readonly ?int $httpStatus = null)
    {
        parent::__construct($failure->message());
    }
}
