<?php

namespace App\Http\Requests\Traffic;

use Illuminate\Foundation\Http\FormRequest;

/** What a page's script tells the traffic tracker. Anyone may send it. */
final class VisitSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'signal' => ['required', 'string', 'in:opened,signup_form'],
            'arrival' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
