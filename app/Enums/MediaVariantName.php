<?php

namespace App\Enums;

enum MediaVariantName: string
{
    case PostFeed640 = 'post_feed_640';
    case PostFeed1280 = 'post_feed_1280';
    case PostDetail1920 = 'post_detail_1920';
    case Avatar128 = 'avatar_128';
    case Avatar256 = 'avatar_256';
}
