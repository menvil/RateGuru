<?php

namespace App\Services\Media\Classification;

use RuntimeException;

/** A classifier gave no answer: unreachable, refused, or answering nonsense. Its message names no file and holds no key. */
final class MediaClassificationFailedException extends RuntimeException {}
