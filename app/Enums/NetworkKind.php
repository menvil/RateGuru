<?php

namespace App\Enums;

/**
 * What kind of network an address belongs to, as the networks' database and
 * lists tell: a hosting, cloud or VPN provider's — no person's own connection
 * — Apple's iCloud Private Relay, which hides a person behind such networks,
 * any other network, or unknown while the networks' database is missing.
 */
enum NetworkKind: string
{
    case Hosting = 'hosting';
    case Relay = 'relay';
    case Other = 'other';
    case Unknown = 'unknown';
}
