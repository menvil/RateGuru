<?php

namespace App\Support\TranslationEngine\Data;

use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;

/**
 * What became of one item: its translation, or why there is none.
 *
 * A successful text has been normalized and checked against every constraint
 * of its item; it is a candidate for the consumer to store, not something the
 * engine has stored. A failure carries a code to act on and a message that is
 * safe to show — never a provider's own words.
 */
final readonly class TranslationItemResult
{
    private function __construct(
        public string $id,
        public TranslationStatus $status,
        public ?string $text,
        public ?TranslationErrorCode $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function success(string $id, string $text): self
    {
        return new self($id, TranslationStatus::Success, $text, null, null);
    }

    public static function failed(string $id, TranslationErrorCode $errorCode, ?string $errorMessage = null): self
    {
        return new self($id, TranslationStatus::Failed, null, $errorCode, $errorMessage ?? $errorCode->message());
    }

    public function isSuccessful(): bool
    {
        return $this->status === TranslationStatus::Success;
    }
}
