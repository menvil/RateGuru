<?php

namespace App\Enums;

enum ModerationActionType: string
{
    case ApprovePost = 'approve_post';
    case RejectPost = 'reject_post';
    case HidePost = 'hide_post';
    case RestorePost = 'restore_post';
    case BanUser = 'ban_user';
    case LimitUser = 'limit_user';
    /**
     * Historical only: restore transitions now log RestoreUserAccess.
     * Kept so old moderation_logs rows keep hydrating.
     */
    case UnbanUser = 'unban_user';
    case RestoreUserAccess = 'restore_user_access';
    case ShadowbanUser = 'shadowban_user';
    case MarkUserTrusted = 'mark_user_trusted';
    case HideComment = 'hide_comment';
    case RestoreComment = 'restore_comment';
}
