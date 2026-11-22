<?php

use App\Console\Commands\AnalyticsWarmFiguresCommand;
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

// Which English each project translation was made from (docs/architecture/
// translation-engine.md, "Outdated translations"): Translation Center records
// it with every save; this gives a translation saved some other way — a
// preset, the deploy's backfill — the English it stands beside now. It only
// adds records, never replaces one, so a change of English stays visible.
Schedule::command('translations:baseline-sources')->daily()->withoutOverlapping();

// What playing games recorded (docs/architecture/games.md): game events older
// than games.retention_days.events (400) and emails left in games older than
// games.retention_days.email_captures (365) are deleted in small batches.
// Attempts, answers and results are kept.
Schedule::command('games:purge-expired')->daily()->withoutOverlapping();

// What the tracker recorded (docs/architecture/traffic.md, "Retention"):
// visits after traffic.retention_days.visits (400) with their conversions,
// click details and postbacks after 90, spend figures and the block log after
// 400, in small batches. Sources, campaigns, prices and attributions stay.
Schedule::command('traffic:purge-expired')->daily()->withoutOverlapping();
Schedule::command('traffic:archive-quiet-campaigns')->dailyAt('02:40')->withoutOverlapping();

// What each step of the traffic is worth (docs/architecture/traffic.md,
// "Worth"): worked out from the matured visits at night, and again within
// five minutes of a change to the country groups. Both share one overlap
// lock, so a run never revalues visits while the other does and an older
// run's values are never written over a newer one's; a change to the groups
// made during the nightly run is still caught by the next five-minute check.
Schedule::command('traffic:work-out-worth')->dailyAt('03:10')->createMutexNameUsing('traffic-work-out-worth')->withoutOverlapping(60)->runInBackground();
Schedule::command('traffic:work-out-worth --when-groups-changed')->everyFiveMinutes()->createMutexNameUsing('traffic-work-out-worth')->withoutOverlapping(60)->runInBackground();

// The postbacks waiting to be told to ad networks (docs/architecture/
// traffic.md, "Postbacks"): the table is the queue, sent by this command in
// a process of its own, never by the one queue worker.
Schedule::command('traffic:send-postbacks')->everyMinute()->withoutOverlapping(5)->runInBackground();

// Paid visits that came to rest, settled with the best worth they reached
// (docs/architecture/traffic.md, "Settling"): a postback for those whose
// networks ask for one.
Schedule::command('traffic:settle-visits')->everyMinute()->withoutOverlapping(5)->runInBackground();

// Spend figures shared out over the visits they cover (docs/architecture/
// traffic.md, "Spend"), never in a web request.
Schedule::command('traffic:allocate-spend')->everyMinute()->withoutOverlapping(5)->runInBackground();

// The ad networks' APIs (docs/architecture/traffic.md, "Sync"): one process
// reads spend and campaigns and applies blocks; the block rule queues the
// sites and zones it calls bots or losing every five minutes, so a block
// reaches the network within minutes of the figures that call for it. A run
// with no source set to block by itself reads no figures. A run reads a
// month of figures per source and goes to the background; its overlap lock
// lasts an hour, longer than the slowest run, so two runs never share out
// one day's cap.
Schedule::command('traffic:sync-networks')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('traffic:block-losers')->everyFiveMinutes()->withoutOverlapping(60)->runInBackground();

// Analytics' own rows of the visits (docs/architecture/analytics.md, "What a
// tab reads"): every minute, the days whose visits still change, the older
// days something changed, and — until every day has been — the earlier days
// not filled yet, for up to 45 seconds; once a night the last
// analytics.rollup.recheck_days days again, a check that nothing was missed.
// Both share one overlap lock, so no day is written by two runs at once; it
// lasts ten minutes, longer than the nightly run, short enough that a
// scheduler killed mid-run holds Analytics back for minutes, not a day. The
// nightly run is registered first: at 03:25 both are due, the scheduler
// starts them in the order they are listed, and the one started second finds
// the lock taken and is skipped — so the minute's run gives way to the
// recheck, which reads those days anyway, and not the other way round. The
// minutes after it skip while it runs; the rows stay current enough for that.
Schedule::command('analytics:refresh-visits --recheck='.(int) config('analytics.rollup.recheck_days').' --seconds=540')->dailyAt('03:25')->createMutexNameUsing('analytics-refresh-visits')->withoutOverlapping(10)->runInBackground();
Schedule::command('analytics:refresh-visits')->everyMinute()->createMutexNameUsing('analytics-refresh-visits')->withoutOverlapping(10)->runInBackground();

// The figures of Growth → Analytics read ahead of the administrators
// (docs/architecture/analytics.md, "The figures kept, and read ahead"): every
// five minutes, each tab slow to read for the views likeliest to be opened,
// kept for everyone for analytics.read_cache_minutes (10) — longer than the
// next run takes to come — so an administrator opening one finds it read. A
// run reads nothing once no administrator opened the page for a day, or while
// the tabs read the visits themselves; it starts no read after four minutes,
// and goes to the background so the minute's other commands keep their time.
// Its overlap lock lasts ten minutes: a scheduler killed mid-run holds the
// next runs back for no longer than what was kept lasts.
Schedule::command('analytics:warm-figures')->cron('*/'.AnalyticsWarmFiguresCommand::EVERY_MINUTES.' * * * *')->withoutOverlapping(10)->runInBackground();

// The local IP location database (docs/architecture/geo.md). Every minute,
// but a run with nothing to do ends at once: each edition is checked once a
// day, sooner after a failure or when the administrator asks for a refresh.
// A download and its checks can take minutes, so the run goes to the
// background and keeps the minute's other commands on time; its overlap lock
// lasts an hour — longer than the slowest run, short enough that a scheduler
// killed mid-download stops updates for an hour, not a day. Two runs at once
// are kept apart by a file lock as well.
Schedule::command('geo:update')->everyMinute()->withoutOverlapping(60)->runInBackground();

// The list of throwaway mail domains a sign-up is checked against. A failed
// fetch leaves the list it had.
Schedule::command('disposable:update')->weekly()->withoutOverlapping();

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
