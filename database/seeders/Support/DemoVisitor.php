<?php

namespace Database\Seeders\Support;

use App\Enums\TrafficDevice;

/**
 * One browser of the demo's history, across its visits: where it is, what
 * it plays on, how good its taste is, whether robots drive it, and what it
 * became — an account of its own, or a member's who signed in on it.
 */
final class DemoVisitor
{
    /** The account this browser belongs to, once one signed up or signed in on it. */
    public ?int $userId = null;

    /** Whether that account signed up here, rather than signing in to one it had. */
    public bool $signedUp = false;

    /** How many visits it has made so far. */
    public int $visits = 0;

    /** @var list<string> the attempts it started as a guest, by public id */
    public array $guestAttempts = [];

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $country  country, region, city, group
     * @param  float  $taste  how often it picks what the crowd picked
     * @param  int|null  $memberId  a member of the site it belongs to before the demo starts
     */
    public function __construct(
        public readonly string $browserHash,
        public readonly array $country,
        public readonly TrafficDevice $device,
        public readonly float $taste,
        public readonly bool $bot = false,
        public readonly ?int $memberId = null,
    ) {}
}
