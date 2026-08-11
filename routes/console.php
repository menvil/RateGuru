<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Non-orphan mode only, never --force: eligibility itself (grace-expired +
// unreferenced, re-verified under lock by MediaLifecycleService::purge())
// is the safety gate for this default mode — see docs/architecture/media.md.
// Physical-orphan deletion stays a manual, explicit `--orphans --force`
// operation and is never scheduled.
Schedule::command('media:purge')->daily()->withoutOverlapping();
