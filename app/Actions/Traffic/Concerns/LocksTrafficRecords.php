<?php

namespace App\Actions\Traffic\Concerns;

use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;

/**
 * How every Traffic sources write takes its locks, in one order: the
 * administrator's `users` row first (docs/architecture/user-lifecycle.md),
 * then the source, then a campaign of it. Everything is asked again on the
 * locked rows: the administrator may still manage traffic sources, and the
 * row is still there at the version its editor was drawn from, when the
 * caller names one.
 */
trait LocksTrafficRecords
{
    use LocksUsersInOrder;

    /** @throws CannotManageTrafficException */
    private function lockAdministrator(User $admin): User
    {
        $locked = $this->lockUsersInOrder((int) $admin->getKey())->get($admin->getKey());

        if ($locked === null || ! $locked->can('manage-traffic-sources')) {
            throw CannotManageTrafficException::becauseUserIsNotAllowed();
        }

        return $locked;
    }

    /** @throws CannotManageTrafficException */
    private function lockSource(int $sourceId, ?int $expectedLockVersion = null): TrafficSource
    {
        $source = TrafficSource::query()->whereKey($sourceId)->lockForUpdate()->first();

        if ($source === null) {
            throw CannotManageTrafficException::becauseItIsGone();
        }

        if ($expectedLockVersion !== null && $source->lock_version !== $expectedLockVersion) {
            throw CannotManageTrafficException::becauseItChanged();
        }

        return $source;
    }

    /** @throws CannotManageTrafficException */
    private function lockCampaign(int $campaignId, ?int $expectedLockVersion = null): TrafficCampaign
    {
        $sourceId = TrafficCampaign::query()->whereKey($campaignId)->value('source_id');

        if ($sourceId === null) {
            throw CannotManageTrafficException::becauseItIsGone('This campaign');
        }

        $this->lockSource((int) $sourceId);
        $campaign = TrafficCampaign::query()->whereKey($campaignId)->lockForUpdate()->first();

        if ($campaign === null) {
            throw CannotManageTrafficException::becauseItIsGone('This campaign');
        }

        if ($expectedLockVersion !== null && $campaign->lock_version !== $expectedLockVersion) {
            throw CannotManageTrafficException::becauseItChanged('this campaign');
        }

        return $campaign;
    }
}
