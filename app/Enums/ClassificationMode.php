<?php

namespace App\Enums;

/** Why a classifier ran on an asset. */
enum ClassificationMode: string
{
    // Recorded, deciding nothing: a classifier on trial against real uploads.
    case Shadow = 'shadow';
    // Its result decides the asset's rating.
    case Active = 'active';
    // Run over a labelled set to compare classifiers.
    case Benchmark = 'benchmark';
}
