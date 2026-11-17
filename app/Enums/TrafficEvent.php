<?php

namespace App\Enums;

/**
 * The moments of a visitor's way the tracker records, each once per visit,
 * in its own column (`traffic_visits.{event}_at`). Twelve of them and the
 * confirmed account can be reported to the network that sent the visitor;
 * the rest are counted only.
 */
enum TrafficEvent: string
{
    case Opened = 'opened';
    case Started = 'started';
    case Halfway = 'halfway';
    case Completed = 'completed';
    case Goal = 'goal';
    case Email = 'email';
    case Shared = 'shared';
    case Replayed = 'replayed';
    case PostOpened = 'post_opened';
    case PostVoted = 'post_voted';
    case PostRated = 'post_rated';
    case FeedVoted = 'feed_voted';
    case NextGame = 'next_game';
    case Page = 'page';
    case Feed = 'feed';
    case SignupForm = 'signup_form';
    case Signup = 'signup';
    case Account = 'account';
    case Login = 'login';
    case PostPublished = 'post_published';
    case Returned = 'returned';

    public function column(): string
    {
        return $this->value.'_at';
    }

    /** Whether a network can be told of it. */
    public function canPostBack(): bool
    {
        return match ($this) {
            self::Opened, self::Started, self::Halfway, self::Completed, self::Goal, self::Email,
            self::Shared, self::Replayed, self::PostOpened, self::PostVoted, self::PostRated,
            self::FeedVoted, self::Account => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function postbackEvents(): array
    {
        return array_values(array_filter(self::cases(), fn (self $event): bool => $event->canPostBack()));
    }

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Page opened',
            self::Started => 'Game started',
            self::Halfway => 'Halfway through',
            self::Completed => 'Game finished',
            self::Goal => 'Flow goal reached',
            self::Email => 'Email left',
            self::Shared => 'Result shared',
            self::Replayed => 'Played again',
            self::PostOpened => 'Opened the post',
            self::PostVoted => 'Voted on the post',
            self::PostRated => 'Rated the post',
            self::FeedVoted => 'Voted in the feed',
            self::NextGame => 'Next game started',
            self::Page => 'Went to the page',
            self::Feed => 'Went to the feed',
            self::SignupForm => 'Saw a sign-up form',
            self::Signup => 'Signed up',
            self::Account => 'New account confirmed',
            self::Login => 'Existing account signed in',
            self::PostPublished => 'Published a post',
            self::Returned => 'Came back',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Opened => 'The page ran in a real browser',
            self::Started => 'Pressed Start',
            self::Halfway => 'Half the game: half the rounds answered',
            self::Completed => 'Answered the last round',
            self::Goal => 'The goal of the visitor’s flow: an account, a share, a vote on a post and so on',
            self::Email => 'Email Capture submitted',
            self::Shared => 'First share click',
            self::Replayed => 'Another attempt started',
            self::PostOpened => 'Followed Go to Post or Go to a post on Full Result',
            self::PostVoted => 'Up or down vote on that post within 30 minutes',
            self::PostRated => 'Answered a rating group on that post within 30 minutes',
            self::FeedVoted => '3 votes in the feed within 30 minutes of the game',
            self::NextGame => 'Started the game the flow suggested',
            self::Page => 'Followed Go to Page',
            self::Feed => 'Followed Go to Feed',
            self::SignupForm => 'A Registration step, Full Result, or the form a vote or a comment opens',
            self::Signup => 'Signed up, email not confirmed yet',
            self::Account => 'Signed up on any page of the site within the source’s window after the click, counted once the email is confirmed',
            self::Login => 'Signed in to an account they already had: worth nothing new',
            self::PostPublished => 'A first post published within 30 days of signing up',
            self::Returned => 'Back on another day within 7 days of signing up',
        };
    }
}
