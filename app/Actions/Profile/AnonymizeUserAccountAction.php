<?php

namespace App\Actions\Profile;

use App\Enums\ProfileActivityVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Follow;
use App\Models\PasswordResetToken;
use App\Models\PostSave;
use App\Models\Session;
use App\Models\User;
use App\Services\Media\MediaLifecycleService;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The reusable domain operation behind account deletion: turns a User row
 * into an irreversible anonymized tombstone while preserving all community
 * contribution (posts, comments, replies, votes, reports, post media).
 *
 * Deliberately free of transport concerns — no logout, no session
 * regeneration, no HTTP. DeleteUserAccountAction wraps this for the
 * self-service flow; a future admin-assisted deletion calls it directly.
 *
 * Everything here is DB-only: the avatar is detached and released through
 * MediaLifecycleService (soft-delete + the normal media:purge grace
 * period). No filesystem I/O happens inside the transaction.
 */
final class AnonymizeUserAccountAction
{
    public function __construct(
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    public function execute(User $user): void
    {
        $anonymized = DB::transaction(function () use ($user): bool {
            // Re-queried and locked rather than trusting the caller's
            // possibly-stale instance: the lock serializes this against
            // concurrent profile updates and a concurrent second delete
            // request, so the committed tombstone can never be partially
            // overwritten and the idempotency check below is race-free.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Retry-safe no-op: an already-tombstoned account must never be
            // anonymized twice — a second pass would mint a new identity and
            // re-run cleanup against rows that no longer exist.
            if ($locked->status === UserStatus::Deleted || $locked->anonymized_at !== null) {
                $user->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $oldEmail = (string) $locked->email;
            $avatarAssetId = $locked->avatarAsset?->id;

            $locked->forceFill([
                'name' => 'Deleted user',
                'display_name' => null,
                'username' => $this->tombstoneUsername($locked->id),
                'email' => $this->tombstoneEmail($locked->id),
                'email_verified_at' => null,
                // Random unusable credential; never stored or logged
                // anywhere else. Combined with the scrambled email this
                // makes the account permanently unauthenticatable.
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'bio' => null,
                'profile_website_url' => null,
                'locale' => null,
                'theme_preference' => null,
                'notify_followed_author_posts' => false,
                'rating_activity_visibility' => ProfileActivityVisibility::Private,
                // A deleted former admin/moderator must not remain a
                // privileged tombstone; Deleted also fails closed on every
                // lifecycle capability, including panel eligibility.
                'role' => UserRole::User,
                'trust_level' => 0,
                'status' => UserStatus::Deleted,
                'avatar_asset_id' => null,
                'anonymized_at' => now(),
            ])->save();

            // Private/social account state. Community contribution (posts,
            // comments, votes, reports and their media) is intentionally
            // never touched here.
            Follow::query()
                ->where('follower_id', $locked->id)
                ->orWhere('author_id', $locked->id)
                ->delete();

            PostSave::query()->where('user_id', $locked->id)->delete();

            $locked->notifications()->delete();

            // The sessions table exists in the base schema for every DB
            // engine regardless of the configured session driver; with the
            // database driver this revokes every other device immediately.
            Session::query()->where('user_id', $locked->id)->delete();

            PasswordResetToken::query()->where('email', $oldEmail)->delete();

            // DB-only release of the now-detached avatar: soft-deletes the
            // asset only if nothing else references it; physical files wait
            // for media:purge's grace period as usual. Post images are
            // deliberately NOT collected — posts survive their author.
            if ($avatarAssetId !== null) {
                $this->lifecycleService->releaseUnreferenced(collect([$avatarAssetId]));
            }

            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($anonymized) {
            // Deliberately PII-free: no old email/username/tokens.
            $this->logger->info('profile.account_anonymized', [
                'user_id' => $user->getKey(),
            ]);
        }
    }

    private function tombstoneUsername(int $userId): string
    {
        return sprintf('deleted_%d_%s', $userId, Str::lower(Str::random(12)));
    }

    private function tombstoneEmail(int $userId): string
    {
        // .invalid is reserved (RFC 2606): this address can never route.
        return sprintf('deleted-%d-%s@deleted.invalid', $userId, Str::uuid()->toString());
    }
}
