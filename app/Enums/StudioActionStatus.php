<?php

namespace App\Enums;

/**
 * Where one scheduled write of a publication plan stands
 * (docs/architecture/studio-plans.md, "The action it writes"):
 *
 * - Pending: waits for its `run_after` and for its parent to be done.
 * - Held: stopped until an administrator resumes it — its actor Limited or
 *   Shadowbanned, or an administrator's decision.
 * - Dispatched: handed to the queue and not yet claimed.
 * - Running: claimed, its domain write not yet committed.
 * - Done: its result written, never run again.
 * - Failed: an unexpected error, or no attempts left; an administrator
 *   retries or cancels it.
 * - Cancelled: for good, with the reason why.
 *
 * Approval writes every new action Pending, and nothing moves an action on
 * yet: Pending is a passive row no queue, worker or schedule reads.
 */
enum StudioActionStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
