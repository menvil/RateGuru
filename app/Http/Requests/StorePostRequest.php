<?php

namespace App\Http\Requests;

use App\Enums\OriginType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canCreateContent() ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'image'       => ['required', 'image', 'max:5120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'source_url'   => ['nullable', 'url', 'max:2048'],
            'origin_truth' => ['nullable', Rule::enum(OriginType::class)],
        ];
    }
}
