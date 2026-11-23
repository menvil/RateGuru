<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app(\App\Support\Locale\LocaleManager::class)->direction() }}">
    <body>
        {{-- Webmail such as Gmail drops the root and the body with their dir:
             the wrapper is what still says which way the lines run there. --}}
        <div dir="{{ app(\App\Support\Locale\LocaleManager::class)->direction() }}">
            <h1>{{ __('mail.contact.heading') }}</h1>

            {{-- What the sender wrote reads its own way: an Arabic message in an
                 English email, or the other way round, keeps its punctuation
                 at its own end. --}}

            <p><strong>{{ __('mail.contact.name') }}:</strong> <bdi>{{ $senderName }}</bdi></p>
            <p><strong>{{ __('mail.contact.email') }}:</strong> {{ $senderEmail }}</p>
            <p><strong>{{ __('mail.contact.message_subject') }}:</strong> <bdi>{{ $messageSubject }}</bdi></p>

            <p {{ \App\Support\Locale\WrittenText::dir($messageBody) }} style="white-space: pre-wrap;">{{ $messageBody }}</p>
        </div>
    </body>
</html>
