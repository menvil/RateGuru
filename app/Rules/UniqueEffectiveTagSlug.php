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
    /** The longest slug a tag may have, typed or made from its name. */
    public const MOST_CHARACTERS = 100;

    private bool $slugifiedToEmpty = false;

    private bool $tooLong = false;

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

        // A typed slug is held to the limit by the field's own rule; one made
        // from the name is not, and transliteration can make it longer than
        // the name — up to three letters for one. Held here, so a tag never
        // gets a slug its own editor would refuse.
        if (strlen($effective) > self::MOST_CHARACTERS) {
            $this->tooLong = true;

            return false;
        }

        $query = Tag::query()->where('slug', $effective);

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        return ! $query->exists();
    }

    public function message(): string
    {
        return match (true) {
            $this->slugifiedToEmpty => 'The slug must contain at least one letter or number.',
            $this->tooLong => 'The slug made from this name would be longer than '.self::MOST_CHARACTERS.' characters. Type a shorter slug.',
            default => 'The slug has already been taken.',
        };
    }
}
