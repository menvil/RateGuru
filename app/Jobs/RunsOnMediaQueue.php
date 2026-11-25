<?php

namespace App\Jobs;

/**
 * A job that runs on MediaQueue's connection and is held to that
 * connection's retry window rather than the default one
 * (QueuedWorkRetryWindowTest).
 */
interface RunsOnMediaQueue {}
