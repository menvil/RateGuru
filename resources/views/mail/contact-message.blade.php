<!DOCTYPE html>
<html lang="en">
    <body>
        <h1>New RateGuru contact message</h1>

        <p><strong>Name:</strong> {{ $senderName }}</p>
        <p><strong>Email:</strong> {{ $senderEmail }}</p>
        <p><strong>Subject:</strong> {{ $messageSubject }}</p>

        <p style="white-space: pre-wrap;">{{ $messageBody }}</p>
    </body>
</html>
