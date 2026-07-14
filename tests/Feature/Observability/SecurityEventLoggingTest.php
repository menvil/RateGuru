<?php

use App\Actions\Follows\FollowAuthorAction;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Import\UrlImportValidator;
use Illuminate\Support\Facades\Log;

it('logs unsafe url as security event', function () {
    Log::spy();

    try {
        app(UrlImportValidator::class)->validate('http://127.0.0.1/admin');
    } catch (UnsafeImportUrlException) {
        // expected
    }

    Log::shouldHaveReceived('warning')
        ->with('security.unsafe_url_blocked', Mockery::on(
            fn ($context) => ($context['event_type'] ?? null) === 'security'
        ));
});

it('logs feature disabled action attempt', function () {
    Log::spy();

    ProjectSettings::factory()->create([
        'feature_flags' => ['show_follow_buttons' => false],
    ]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    try {
        app(FollowAuthorAction::class)->handle($follower, $author);
    } catch (FollowFeatureDisabledException) {
        // expected
    }

    Log::shouldHaveReceived('warning')
        ->with('security.feature_disabled_action_attempted', Mockery::any());
});

it('logs invalid scheme as security event', function () {
    Log::spy();

    try {
        app(UrlImportValidator::class)->validate('ftp://example.com/file');
    } catch (UnsafeImportUrlException) {
        // expected
    }

    Log::shouldHaveReceived('warning')
        ->with('security.unsafe_url_blocked', Mockery::on(
            fn ($context) => ($context['event_type'] ?? null) === 'security'
        ));
});
