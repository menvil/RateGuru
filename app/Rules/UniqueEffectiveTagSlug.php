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
    private bool $slugifiedToEmpty = false;

    public function __construct(
        private readonly ?string $name,
        private readonly int|string|null $ignoreId = null,
    ) {}

    public function passes($attribute, $value): bool
    {
        $hasInput = filled($value) || filled($this->name);
        $effective = Str::slug(filled($value) ? (string) $value : (string) $this->name);

        if ($effective === '') {
            // With no input at all, defer to the name field's "required"
            // rule. But input that slugifies away (e.g. only punctuation)
            // is an invalid slug we must reject here rather than persist an
            // empty slug and let it surface as a DB unique error later.
            $this->slugifiedToEmpty = $hasInput;

            return ! $hasInput;
        }

        $query = Tag::query()->where('slug', $effective);

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        return ! $query->exists();
    }

    public function message(): string
    {
        return $this->slugifiedToEmpty
            ? 'The slug must contain at least one letter or number.'
            : 'The slug has already been taken.';
    }
}
