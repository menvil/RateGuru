<?php

namespace App\Enums;

/**
 * What a discussion generation writes (docs/architecture/studio-plans.md,
 * "Discussion generation"): a whole new discussion, or the text of one part
 * of the one the plan has — a comment on the post, a reply, or a comment
 * with every reply it has. A part keeps its ids, its authors, its places and
 * its times; only its text is written again.
 */
enum StudioDiscussionGenerationMode: string
{
    case FullDiscussion = 'full_discussion';
    case RegenerateComment = 'regenerate_comment';
    case RegenerateReply = 'regenerate_reply';
    case RegenerateBranch = 'regenerate_branch';

    /** Whether it writes one part of the plan's discussion again, rather than a whole new one. */
    public function isTargeted(): bool
    {
        return $this !== self::FullDiscussion;
    }
}
