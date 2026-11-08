<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

final class SubmitContactMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Any case: an address someone types with capitals is still
            // theirs. And only an address the mailer can put in Reply-To —
            // its check is stricter than the validator's, and failing there
            // happens in the queue, after the visitor was told it was sent.
            'email' => ['bail', 'required', 'string', 'email', 'max:254', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    new Address((string) $value);
                } catch (RfcComplianceException) {
                    $fail(__('validation.email', ['attribute' => __('validation.attributes.email')]));
                }
            }],
            'subject' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
