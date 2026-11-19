<?php

namespace App\Enums;

/** The tracked moments of a game (game_events). */
enum GameEventType: string
{
    case View = 'view';
    case Assigned = 'assigned';
    case StepViewed = 'step_viewed';
    case Started = 'started';
    case RoundAnswered = 'round_answered';
    case Completed = 'completed';
    case ResultViewed = 'result_viewed';
    case EmailCaptured = 'email_captured';
    case RegistrationCtaClicked = 'registration_cta_clicked';
    case AccountAttributed = 'account_attributed';
    case ShareClicked = 'share_clicked';
    case Replay = 'replay';
    case Exit = 'exit';
    case Halfway = 'halfway';
    case GoalReached = 'goal_reached';
    case PostOpened = 'post_opened';
    case PostVoted = 'post_voted';
    case PostRated = 'post_rated';
    case FeedVoted = 'feed_voted';
    case AccountConfirmed = 'account_confirmed';
    case PostSignInForm = 'post_sign_in_form';
}
