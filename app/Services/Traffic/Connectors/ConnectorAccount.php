<?php

namespace App\Services\Traffic\Connectors;

/** The network account a key opens: what to call it, and its currency. */
final readonly class ConnectorAccount
{
    public function __construct(public string $label, public string $currency) {}
}
