<?php

namespace App\Support\Traffic\Attribution;

use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Throwable;

/**
 * An email confirmed — by its link, or by claiming an account with a
 * confirmed address — confirms the new account it belongs to, once.
 * Reported, never thrown at the person confirming.
 */
final class ConfirmAttributionOnVerified
{
    public function __construct(private readonly ConfirmAccountAttributionAction $confirm) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->confirm->handle((int) $event->user->id, CarbonImmutable::now());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
