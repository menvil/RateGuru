<?php

namespace App\Support\Posts;

/**
 * The bounds a post's own fields keep, wherever a post is written from: the
 * public upload form holds a new post to them, and a Content Studio plan the
 * post it will become.
 */
final class PostContentRules
{
    /** The shortest title, in characters. */
    public const TITLE_MIN_LENGTH = 3;

    /** The longest title, in characters. */
    public const TITLE_LIMIT = 120;

    /** The longest description, in characters. */
    public const DESCRIPTION_LIMIT = 2000;

    /** The longest source address, in characters. */
    public const SOURCE_URL_LIMIT = 2048;

    /** The most tags a post carries. */
    public const MOST_TAGS = 10;
}
