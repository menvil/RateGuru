<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaIsNotPublicException;

/**
 * Public URL resolution only, and a pure data boundary: takes a location and
 * a visibility, nothing else. Never touches the database, never lazy-loads a
 * relation, never decides what a MediaAsset or MediaVariant's own visibility
 * is — the caller (a presenter, in this codebase) already has that loaded
 * and passes it in explicitly. For a MediaVariant, that means the caller
 * passes its parent asset's visibility, not the variant's own (it has none).
 */
interface MediaUrlResolver
{
    /**
     * @throws MediaIsNotPublicException if the media is not public
     */
    public function publicUrl(MediaLocation $location, MediaVisibility $visibility): string;

    public function publicUrlOrNull(?MediaLocation $location, ?MediaVisibility $visibility): ?string;
}
