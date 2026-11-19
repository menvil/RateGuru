<?php

namespace App\Enums;

/**
 * The moments of a play the games tell the traffic tracker about and a flow's
 * goal can be: in the game, after the result, and on the site the flow sent
 * the visitor to. Each value is the tracker's event of the same name.
 */
enum GameMilestone: string
{
    case Started = 'started';
    case Halfway = 'halfway';
    case Completed = 'completed';
    case Email = 'email';
    case Shared = 'shared';
    case Replayed = 'replayed';
    case NextGame = 'next_game';
    case Page = 'page';
    case Feed = 'feed';
    case PostOpened = 'post_opened';
    case PostVoted = 'post_voted';
    case PostRated = 'post_rated';
    case FeedVoted = 'feed_voted';
}
