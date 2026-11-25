<?php

namespace App\Exceptions\Languages;

use RuntimeException;

/**
 * OpenAI could not tell the languages of a batch of texts. The reason is a code, never the provider's answer:
 * what came back may echo the texts, and the request carried the API key.
 */
final class LanguageDetectionFailed extends RuntimeException
{
    /** Unreachable, slow or failing on its side: the run stops asking until the next one. */
    public const UNAVAILABLE = 'unavailable';

    /** It answered, but not with languages for the batch — a refusal, or an answer of the wrong shape. */
    public const UNUSABLE = 'unusable';

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function unavailable(): bool
    {
        return $this->getMessage() === self::UNAVAILABLE;
    }
}
