<?php

namespace App\Enums;

/**
 * The moments of a visitor's way the tracker records, each once per visit,
 * in its own column (`traffic_visits.{event}_at`). Twelve of them, the
 * confirmed account and the visit settling can be reported to the network
 * that sent the visitor; the rest are counted only — an account activated
 * among them, which never makes a conversion, and the registration steps
 * between the sign-in dialog and an account (RecordRegistrationStepAction),
 * which are no step of worth either.
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
    case Settled = 'settled';
    case Activated = 'activated';
    case AuthOpened = 'auth_opened';
    case RegisterForm = 'register_form';
    case SocialStarted = 'social_started';
    case SignupSubmitted = 'signup_submitted';
    case SignupRefused = 'signup_refused';

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
            self::FeedVoted, self::Account, self::Settled => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function postbackEvents(): array
    {
        return array_values(array_filter(self::cases(), fn (self $event): bool => $event->canPostBack()));
    }

    /**
     * How far a settled visit may be asked to have got before its network is
     * told of it, the least first.
     *
     * @return list<self>
     */
    public static function settledFloors(): array
    {
        return [self::Opened, self::Started, self::Halfway, self::Completed, self::Goal, self::Account];
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
            self::Settled => 'Visit settled',
            self::Activated => 'Account activated',
            self::AuthOpened => 'Opened sign-in or sign-up',
            self::RegisterForm => 'Saw the registration form',
            self::SocialStarted => 'Went on to Google or Facebook',
            self::SignupSubmitted => 'Sent the sign-up form',
            self::SignupRefused => 'Sign-up refused',
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
            self::Page => 'Followed Go to Page, or Go to a page on Full Result',
            self::Feed => 'Followed Go to Feed',
            self::SignupForm => 'A Registration step, Full Result, or the form a vote or a comment opens',
            self::Signup => 'Signed up, email not confirmed yet',
            self::Account => 'Signed up on any page of the site within the source’s window after the click, counted once the email is confirmed',
            self::Login => 'Signed in to an account they already had: not a new user, worth the sign-in price',
            self::PostPublished => 'A first post published within 30 days of signing up',
            self::Returned => 'Back on another day within 7 days of signing up',
            self::Settled => 'Came to rest — its account confirmed, 30 minutes without a new step, or a day after the click — with the best worth it reached',
            self::Activated => 'The confirmed account came back on one of the 7 days after signing up and voted, commented, posted or started a game',
            self::AuthOpened => 'The sign-in dialog opened, or a page or a game showed a sign-in or sign-up form — the first opener kept',
            self::RegisterForm => 'The registration form shown: the dialog in its sign-up state, the sign-up page or a game’s form',
            self::SocialStarted => 'Pressed Continue with Google or Facebook: a sign-up or a sign-in, the provider tells which',
            self::SignupSubmitted => 'Sent the email sign-up form, whatever came of it',
            self::SignupRefused => 'A sign-up form sent and refused — every reason kept, never what was typed',
        };
    }
}
