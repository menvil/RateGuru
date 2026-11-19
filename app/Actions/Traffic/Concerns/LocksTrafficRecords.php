<?php

namespace App\Actions\Traffic\Concerns;

use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;

/**
 * How every Traffic sources write takes its locks, in one order: the
 * administrator's `users` row first (docs/architecture/user-lifecycle.md),
 * then the source, then a campaign or a segment of it. Everything is asked
 * again on the locked rows: the administrator may still manage traffic
 * sources, and the row is still there at the version its editor was drawn
 * from, when the caller names one.
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

    /**
     * A segment of a source, locked after its source: one deleted only when
     * the caller asks for it, as Undo does.
     *
     * @throws CannotManageTrafficException
     */
    private function lockSegment(int $segmentId, ?int $expectedLockVersion = null, bool $deleted = false): TrafficSegment
    {
        $sourceId = TrafficSegment::query()->withTrashed()->whereKey($segmentId)->value('source_id');

        if ($sourceId === null) {
            throw CannotManageTrafficException::becauseItIsGone('This segment');
        }

        $this->lockSource((int) $sourceId);
        $segment = TrafficSegment::query()->withTrashed()->whereKey($segmentId)->lockForUpdate()->first();

        if ($segment === null || ($segment->trashed() && ! $deleted)) {
            throw CannotManageTrafficException::becauseItIsGone('This segment');
        }

        if ($expectedLockVersion !== null && $segment->lock_version !== $expectedLockVersion) {
            throw CannotManageTrafficException::becauseItChanged('this segment');
        }

        return $segment;
    }
}
