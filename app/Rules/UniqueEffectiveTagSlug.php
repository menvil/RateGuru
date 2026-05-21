<?php

namespace App\Rules;

use App\Models\Tag;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Support\Str;

/**
 * Validates uniqueness of the *effective* tag slug — the value that will
 * actually be saved, including the name-derived fallback applied when the
 * slug field is left blank.
 *
 * Implements the deprecated ImplicitRule interface on purpose: the slug
 * field is optional, and Laravel skips non-implicit rules when a field is
 * empty. The new ValidationRule interface has no implicit variant, so a
 * blank slug whose generated value collides would otherwise bypass
 * validation and hit the DB unique index.
 */
final class UniqueEffectiveTagSlug implements ImplicitRule
{
    public function __construct(
        private readonly ?string $name,
        private readonly int|string|null $ignoreId = null,
    ) {}

    public function passes($attribute, $value): bool
    {
        $effective = Str::slug(filled($value) ? (string) $value : (string) $this->name);

        // An empty effective slug is a "name required" concern, handled by
        // the name field's own validation — not a uniqueness failure.
        if ($effective === '') {
            return true;
        }

        $query = Tag::query()->where('slug', $effective);

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        return ! $query->exists();
    }

    public function message(): string
    {
        return 'The slug has already been taken.';
    }
}
