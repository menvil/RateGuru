<?php

return [
    // What the checks on a sign-up do: off, report (log and let through) or
    // enforce (refuse). See App\Enums\RegistrationScreeningMode.
    'screening_mode' => env('REGISTRATION_SCREENING_MODE', 'report'),

    // A form sent sooner than this after it was drawn was not filled in by a
    // person. Short, because a password manager fills one in a moment.
    'minimum_fill_seconds' => 2,
];
