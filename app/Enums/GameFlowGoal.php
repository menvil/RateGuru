<?php

namespace App\Enums;

/**
 * What a flow is for: the one thing a play of it counts as having achieved.
 * Game analytics, version comparison and postbacks count it, at most once per
 * attempt (docs/architecture/games.md, "Goals"). The goals reached on the site
 * after the game — an account, a vote on the post the flow sent the visitor
 * to, votes in the feed — are credited to the attempt within their windows.
 */
enum GameFlowGoal: string
{
    case Account = 'account';
    case NewAccount = 'new_account';
    case Email = 'email';
    case Shared = 'shared';
    case PostOpened = 'post_opened';
    case PostVoted = 'post_voted';
    case PostRated = 'post_rated';
    case FeedVoted = 'feed_voted';
    case NextGame = 'next_game';
    case Page = 'page';
    case Feed = 'feed';
    case Replayed = 'replayed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account confirmed',
            self::NewAccount => 'New account confirmed',
            self::Email => 'Email left',
            self::Shared => 'Result shared',
            self::PostOpened => 'Opened the post',
            self::PostVoted => 'Voted on the post',
            self::PostRated => 'Rated the post',
            self::FeedVoted => 'Voted in the feed',
            self::NextGame => 'Next game started',
            self::Page => 'Went to the page',
            self::Feed => 'Went to the feed',
            self::Replayed => 'Played again',
            self::Completed => 'Game finished',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Account => 'Sign-up or log-in, confirmed',
            self::NewAccount => 'Only new accounts',
            self::Email => 'Email Capture submitted',
            self::Shared => 'First share click',
            self::PostOpened => 'The post the flow sends them to',
            self::PostVoted => 'Up or down vote within 30 minutes',
            self::PostRated => 'A rating group within 30 minutes',
            self::FeedVoted => '3 votes in the feed',
            self::NextGame => 'The game Next Game suggests',
            self::Page => 'The Go to Page button',
            self::Feed => 'The Go to Feed button',
            self::Replayed => 'Another attempt from the result',
            self::Completed => 'The last round answered',
        };
    }

    /** What makes it count, as the editor explains it. */
    public function counts(): string
    {
        return match ($this) {
            self::Account => 'Counts when the visitor signs up or logs in within 7 days of this play, on any page of the site: the game itself, a post it sent them to, the feed. The account must be confirmed: a confirmed email, or Google or Facebook. Unconfirmed sign-ups are removed after 7 days and never count.',
            self::NewAccount => 'Like Account confirmed, but only new accounts: signed up within 7 days of this play on any page of the site, then confirmed. Logging in to an existing account doesn’t count.',
            self::Email => 'Counts when the visitor submits an email on the Email Capture step. An email is not an account.',
            self::Shared => 'Counts on the first share click, on Full Result or on the Share step.',
            self::PostOpened => 'Counts when the visitor opens the post that Go to Post or Go to a post on Full Result sends them to.',
            self::PostVoted => 'Counts when the visitor votes up or down on the post that Go to Post or Go to a post sent them to, within 30 minutes.',
            self::PostRated => 'Counts when the visitor answers a rating group the post asks for, such as Cuteness, within 30 minutes. A plain up or down vote isn’t enough.',
            self::FeedVoted => 'Counts when the visitor votes on 3 posts within 30 minutes of leaving the game for the feed.',
            self::NextGame => 'Counts when the visitor starts the game the Next Game step suggests.',
            self::Page => 'Counts when the visitor follows the button on the Go to Page step.',
            self::Feed => 'Counts when the visitor follows the button on the Go to Feed step, or Go to the feed on Full Result.',
            self::Replayed => 'Counts when the visitor starts another attempt of this game from the result.',
            self::Completed => 'Counts when the last round is answered. For flows with nothing to do after the result.',
        };
    }

    /** What a flow needs to reach it, or null when every flow can. */
    public function needs(): ?string
    {
        return match ($this) {
            self::Account, self::NewAccount => 'a Registration step, sign-up on Full Result, or a step into the site (Go to Post, Go to Feed, Go to Page on this site)',
            self::Email => 'an Email Capture step',
            self::Shared => 'a Share step or sharing on Full Result',
            self::PostOpened, self::PostVoted => 'a Go to Post step or Go to a post on Full Result',
            self::PostRated => 'a rating group asked on the post Go to Post or Full Result sends them to',
            self::FeedVoted, self::Feed => 'a Go to Feed step or Go to the feed on Full Result',
            self::NextGame => 'a Next Game step',
            self::Page => 'a Go to Page step',
            self::Replayed, self::Completed => null,
        };
    }

    /** The step the editor offers to add when the flow cannot reach it. */
    public function fix(): ?GameFlowStepType
    {
        return match ($this) {
            self::Account, self::NewAccount => GameFlowStepType::Registration,
            self::Email => GameFlowStepType::EmailCapture,
            self::Shared => GameFlowStepType::Share,
            self::PostOpened, self::PostVoted, self::PostRated => GameFlowStepType::GoToPost,
            self::FeedVoted, self::Feed => GameFlowStepType::Feed,
            self::NextGame => GameFlowStepType::NextGame,
            self::Page => GameFlowStepType::GoToPage,
            self::Replayed, self::Completed => null,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Account, self::NewAccount => 'user-plus',
            self::Email => 'mail',
            self::Shared => 'share-2',
            self::PostOpened, self::PostVoted, self::PostRated => 'thumbs-up',
            self::FeedVoted, self::Feed => 'home',
            self::NextGame => 'arrow-right',
            self::Page => 'arrow-up-right',
            self::Replayed => 'rotate-ccw',
            self::Completed => 'trophy',
        };
    }

    /** The step of the traffic tracker the goal is worth as. */
    public function step(): string
    {
        return match ($this) {
            self::Account, self::NewAccount => 'account',
            default => $this->value,
        };
    }

    /** The game milestone that reaches it; null for the goals only an account reaches. */
    public function milestone(): ?GameMilestone
    {
        return match ($this) {
            self::Account, self::NewAccount => null,
            default => GameMilestone::from($this->value),
        };
    }

    /** Whether it can be reached by a visitor of the audience: a signed-in player already has an account. */
    public function appliesTo(GameAudience $audience): bool
    {
        return ! (in_array($this, [self::Account, self::NewAccount], true) && $audience === GameAudience::Authenticated);
    }

    /** Whether the goal is reached on the site after the game, within a window, rather than in the game. */
    public function isReachedOnTheSite(): bool
    {
        return in_array($this, [self::Account, self::NewAccount, self::PostVoted, self::PostRated, self::FeedVoted], true);
    }
}
