<?php

namespace App\Support\TranslationEngine\Exceptions;

use InvalidArgumentException;

/**
 * A translation request that breaks the engine's contract — a consumer's
 * mistake, refused before any provider is chosen or contacted, so no money is
 * spent on it.
 *
 * Messages name the item by the consumer's own id and say what is wrong with
 * it. They never repeat the source text, a context or a translation.
 */
final class InvalidTranslationRequestException extends InvalidArgumentException
{
    public static function forItem(string $id, string $problem): self
    {
        return new self("Translation item [{$id}] {$problem}.");
    }

    /** For an item whose id is itself the problem, so it cannot be named by it. */
    public static function forItemId(string $problem): self
    {
        return new self("A translation item id {$problem}.");
    }

    public static function forBatch(string $problem): self
    {
        return new self("The translation batch {$problem}.");
    }
}
