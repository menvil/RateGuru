<?php

namespace App\Http\Requests;

use App\Support\Locale\LocaleManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeLocaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(app(LocaleManager::class)->enabledCodes())],
        ];
    }
}
