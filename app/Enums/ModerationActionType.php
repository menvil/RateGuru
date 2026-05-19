<?php

namespace App\Enums;

enum ModerationActionType: string
{
    case ApprovePost = 'approve_post';
    case RejectPost = 'reject_post';
    case HidePost = 'hide_post';
    case RestorePost = 'restore_post';
    case BanUser = 'ban_user';
    case ShadowbanUser = 'shadowban_user';
}
