<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * Base type for every rejection of an uploaded or imported video or animated
 * GIF. Messages never carry a path or a tool's output, so a caller may show
 * a safe, generic "this video could not be processed" by catching this alone.
 */
abstract class VideoIngestException extends RuntimeException {}
