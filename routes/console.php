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

// The Hot feed's stored score decays with a post's age, but a vote or a
// comment is what recalculates it: without this sweep a post nobody touches
// keeps the score it had at its last activity, and stays on top of Hot. Each
// post is recalculated under its own lock, the one votes and comments take.
// Its overlap lock lasts an hour, not the default day: a scheduler killed in
// the middle of a sweep would otherwise stop the decay for a day.
Schedule::command('posts:recalculate-hot-scores')->hourly()->withoutOverlapping(60);

// Retention purge for author-deleted posts (docs/architecture/
// post-lifecycle.md): eligibility is re-verified per post under lock by
// PostRetentionPurgeService, so the schedule itself carries no policy.
Schedule::command('posts:purge')->daily()->withoutOverlapping();

// Author-deleted leaf comments (docs/architecture/comment-lifecycle.md):
// enabled by default with the 30-day comment retention; structural,
// parent-post, moderation and report holds are re-verified per comment
// under lock. Bad config makes the run fail closed without purging.
Schedule::command('comments:purge-deleted')->daily()->withoutOverlapping();

// Finalized moderation removals (docs/architecture/
// moderation-content-lifecycle.md): disabled by default — with empty
// MODERATION_CONTENT_RETENTION_DAYS this is a cheap safe no-op, and
// setting the variable later enables the same schedule unchanged.
Schedule::command('moderation:purge-content')->daily()->withoutOverlapping();

// Translation AI telemetry (docs/architecture/translation-engine.md): rows of
// translation_ai_provider_calls older than translation.telemetry.retention_days
// (90) are deleted in small batches. Nothing else is touched.
Schedule::command('translations:purge-ai-calls')->daily()->withoutOverlapping();

// What playing games recorded (docs/architecture/games.md): game events older
// than games.retention_days.events (400) and emails left in games older than
// games.retention_days.email_captures (365) are deleted in small batches.
// Attempts, answers and results are kept.
Schedule::command('games:purge-expired')->daily()->withoutOverlapping();

// Accounts that signed up with an email address and did not confirm one
// within a week (docs/architecture/user-lifecycle.md, "Unconfirmed
// accounts"): removed with everything they did, each asked again under lock.
// Staff, Personas and Google or Facebook sign-ins are never among them.
Schedule::command('users:purge-unconfirmed')->daily()->withoutOverlapping();

// Content Studio's dispatcher (docs/architecture/studio-plans.md, "Running a
// plan"): sends the approved plans' actions that are due and sends again
// those lost on their way. The schedule and the recovery: an approval, a run
// done with actions waiting for it, a Retry or a Resume and switching the
// executor on wake the same dispatcher at once (StudioDispatchWake), so a
// plan never waits a minute between generations. Off until the project's
// content_studio_execution_enabled flag is switched on: until then every
// run is a no-op that sends and changes nothing. Its overlap lock lasts five
// minutes, not the default day: a scheduler killed in the middle of a run
// would otherwise stop every send for a day. A run takes seconds, and two
// runs at once never send one action twice, so a lock that lapses early
// costs nothing.
Schedule::command('studio:dispatch-due')->everyMinute()->withoutOverlapping(5);
