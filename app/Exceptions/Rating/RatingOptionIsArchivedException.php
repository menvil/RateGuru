<?php

namespace App\Exceptions\Rating;

use DomainException;

/** An archived option stays inactive until it is taken out of the archive. */
final class RatingOptionIsArchivedException extends DomainException {}
