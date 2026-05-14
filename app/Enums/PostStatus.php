<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Deleted = 'deleted';
}
