<?php

namespace Tests\Fixtures\QueuedWork;

/**
 * Queued only through its parent: this file never names the queue interface,
 * so a guard that searched source text for it would miss the class.
 */
final class InheritedQueuedTask extends QueuedBase
{
    public int $timeout = 120;
}
