<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <body>
        <h1>{{ __('mail.contact.heading') }}</h1>

        <p><strong>{{ __('mail.contact.name') }}:</strong> {{ $senderName }}</p>
        <p><strong>{{ __('mail.contact.email') }}:</strong> {{ $senderEmail }}</p>
        <p><strong>{{ __('mail.contact.message_subject') }}:</strong> {{ $messageSubject }}</p>

        <p style="white-space: pre-wrap;">{{ $messageBody }}</p>
    </body>
</html>
