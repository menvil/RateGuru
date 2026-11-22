<?php

namespace App\Services\Traffic\Connectors;

/**
 * What a campaign's block list became after a change: whether it holds every
 * site or zone added and none removed, and the list itself, by which each of
 * them is judged on its own.
 */
final readonly class BlockDeltaResult
{
    /** @param  list<string>  $listed  the list as the network has it now */
    public function __construct(public bool $applied, public ?int $httpStatus, public array $listed) {}
}
