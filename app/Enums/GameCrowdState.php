<?php

namespace App\Enums;

/**
 * What the site's votes say about a pair of posts. Only Majority lets a round
 * score by the crowd: the others explain why it cannot.
 */
enum GameCrowdState: string
{
    /** One post has the larger upvote share, by the gap and the votes required. */
    case Majority = 'majority';
    /** Both posts have exactly the same upvote share. */
    case Tie = 'tie';
    /** The shares differ by less than the gap required. */
    case Ambiguous = 'ambiguous';
    /** A post has fewer votes than required. */
    case Insufficient = 'insufficient';
    /** A post is no longer public, or its picture is unavailable. */
    case Ineligible = 'ineligible';
    /** An option has no post yet, or both options show the same post. */
    case Missing = 'missing';
}
