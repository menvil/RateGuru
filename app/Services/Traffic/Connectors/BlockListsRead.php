<?php

namespace App\Services\Traffic\Connectors;

/**
 * What a read of campaigns' block lists at one level brought back: each list
 * read, by the campaign's ID with the network, and why the first list that
 * couldn't be read wasn't, naming its campaign. A list that couldn't be read
 * is left out, never taken for an empty one.
 */
final readonly class BlockListsRead
{
    /** @param  array<string, list<string>>  $lists */
    public function __construct(public array $lists, public ?ConnectorException $failure = null) {}
}
