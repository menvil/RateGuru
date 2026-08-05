<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * Base type for every rejection the image ingest pipeline can raise. Callers
 * that want to show a safe, generic "this image couldn't be processed"
 * message without distinguishing the exact reason can catch this type alone.
 */
abstract class ImageIngestException extends RuntimeException {}
