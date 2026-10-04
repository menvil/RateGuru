<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitContactMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254'],
            'subject' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
