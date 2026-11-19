<?php

namespace App\Actions\Analytics;

use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\User;
use App\Support\Analytics\JourneyTimeline;
use App\Support\Observability\DomainLogger;

/**
 * Writes down that an administrator looked up a journey on Analytics →
 * Journeys (analytics.journey.lookup): who looked, the ID asked for and how
 * it was asked — typed into the search, an example or one of several
 * browsers picked, or an address opened. Only an ID is written down, never
 * what was typed on the way to one: anything that is no visitor_… or user_…
 * ID is no lookup, and nothing is written.
 */
final class LogJourneyLookupAction
{
    /** @var list<string> how a journey can be asked for */
    public const array VIA = ['search', 'pick', 'address'];

    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @return bool whether it was written down: false for anything that is no ID
     *
     * @throws CannotManageTrafficException
     */
    public function handle(User $admin, mixed $who, string $via): bool
    {
        if (! $admin->can('manage-traffic-sources')) {
            throw CannotManageTrafficException::becauseUserIsNotAllowed();
        }

        $id = JourneyTimeline::read($who);

        if ($id === null || ! in_array($via, self::VIA, true)) {
            return false;
        }

        $this->logger->info('analytics.journey.lookup', ['journey' => $id['kind'].'_'.$id['value'], 'via' => $via, 'admin_id' => $admin->id]);

        return true;
    }
}
