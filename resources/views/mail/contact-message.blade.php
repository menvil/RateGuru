<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app(\App\Support\Locale\LocaleManager::class)->direction() }}">
    <body>
        {{-- Webmail such as Gmail drops the root and the body with their dir:
             the wrapper is what still says which way the lines run there. --}}
        <div dir="{{ app(\App\Support\Locale\LocaleManager::class)->direction() }}">
            <h1>{{ __('mail.contact.heading') }}</h1>

            <p><strong>{{ __('mail.contact.name') }}:</strong> {{ $senderName }}</p>
            <p><strong>{{ __('mail.contact.email') }}:</strong> {{ $senderEmail }}</p>
            <p><strong>{{ __('mail.contact.message_subject') }}:</strong> {{ $messageSubject }}</p>

            <p style="white-space: pre-wrap;">{{ $messageBody }}</p>
        </div>
    </body>
</html>
