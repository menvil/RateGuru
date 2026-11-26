<?php

namespace App\Enums;

enum MediaVariantName: string
{
    case PostFeed640 = 'post_feed_640';
    case PostFeed1280 = 'post_feed_1280';
    case PostDetail1920 = 'post_detail_1920';
    case Avatar128 = 'avatar_128';
    case Avatar256 = 'avatar_256';
    case OpenGraph = 'open_graph';
    // A video's display renditions, below its master (at most 1080 on the
    // short side): what a feed plays on a phone, and on a slow connection.
    case Video720 = 'video_720';
    case Video480 = 'video_480';
}
