<?php

namespace Tests\Fixtures\QueuedWork;

use Illuminate\Contracts\Queue\ShouldQueue;

/** A queued base class, which the retry-window guard must look past to its concrete children. */
abstract class QueuedBase implements ShouldQueue {}
