<?php

namespace App\Services\Geo;

/** What one run of the IP database update did for an edition. */
enum GeoUpdateOutcome: string
{
    case Idle = 'idle';
    case Busy = 'busy';
    case Current = 'current';
    case Updated = 'updated';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
