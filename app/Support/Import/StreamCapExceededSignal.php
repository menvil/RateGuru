<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * Internal control-flow signal thrown from inside the "progress" curl
 * callback and caught immediately in PinnedImportHttpTransport — never
 * meant to escape that class. Unlike a throw from "on_headers" (which
 * Guzzle's own CurlFactory catches and re-wraps into a
 * Illuminate\Http\Client\ConnectionException), curl's progress callback has
 * no such wrapping, so whatever is thrown from it reaches the caller
 * completely raw. A dedicated type here means that catch is unambiguous,
 * instead of quietly assuming every RuntimeException in that position is
 * this one.
 */
final class StreamCapExceededSignal extends RuntimeException {}
