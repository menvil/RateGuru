<?php

namespace App\Support\Profile;

use App\Enums\ProfileActivityVisibility;

final class ProfileValidationRules
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $visibilityValues = implode(',', ProfileActivityVisibility::values());

        return [
            'display_name' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
            'profile_website_url' => ['nullable', 'url', 'max:255'],
            'rating_activity_visibility' => ['sometimes', 'required', 'string', 'in:' . $visibilityValues],
        ];
    }
}
