<?php

namespace App\Http\Requests\Traffic;

use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Support\Traffic\Capture\ArrivalToken;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a page's script tells the traffic tracker. Anyone may send it; a post
 * it says was shared has to be one its own page would show.
 */
final class VisitSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'signal' => ['required', 'string', 'in:opened,signup_form,post_shared'],
            'arrival' => ['nullable', 'string', 'max:'.ArrivalToken::MAX_LENGTH],
            'post' => [
                // Read only with the signal that names a post.
                'exclude_unless:signal,post_shared',
                'bail',
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(PublishedPostDetailsQuery::class)->isPublished((int) $value)) {
                        $fail('The post is not published.');
                    }
                },
            ],
        ];
    }
}
