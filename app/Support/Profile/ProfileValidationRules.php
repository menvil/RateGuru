<?php

namespace App\Support\Profile;

use App\Enums\ProfileActivityVisibility;
use App\Rules\ContainsNoLink;
use Illuminate\Validation\Rule;

final class ProfileValidationRules
{
    /** The longest public display name, in characters. */
    public const DISPLAY_NAME_LIMIT = 80;

    /** The longest public bio, in characters. */
    public const BIO_LIMIT = 500;

    /**
     * @param  string|null  $currentDisplayName  what the account is called now, which it may keep
     * @return array<string, list<mixed>>
     */
    public function rules(?string $currentDisplayName = null): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:'.self::DISPLAY_NAME_LIMIT, new ContainsNoLink($currentDisplayName)],
            'bio' => ['nullable', 'string', 'max:'.self::BIO_LIMIT],
            'profile_website_url' => ['nullable', 'url', 'max:255'],
            'rating_activity_visibility' => ['sometimes', 'required', 'string', Rule::enum(ProfileActivityVisibility::class)],
        ];
    }
}
