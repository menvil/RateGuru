<?php

namespace App\Enums;

enum ShareProvider: string
{
    case CopyLink = 'copy_link';
    case Native = 'native';
    case Facebook = 'facebook';
    case X = 'x';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Reddit = 'reddit';
    case Pinterest = 'pinterest';
    case Email = 'email';

    public static function urlProviders(): array
    {
        return [
            self::Facebook,
            self::X,
            self::Telegram,
            self::WhatsApp,
            self::Reddit,
            self::Pinterest,
            self::Email,
        ];
    }

    /**
     * Whether a share button for this provider links to our own redirect
     * rather than to the provider's share page. Ad blockers hide every link
     * to facebook.com/sharer, twitter.com/intent/tweet, t.me/share and the
     * like; a link on our own domain is left alone. Email stays a mailto:
     * link, which no blocker hides.
     */
    public function sharesThroughRedirect(): bool
    {
        return $this !== self::Email && in_array($this, self::urlProviders(), true);
    }

    /**
     * Whether a share button for this provider hands over the post's picture
     * itself, not only a link that the provider previews from our page.
     *
     * The others keep their link. Facebook drops any text a share brings
     * along, so a picture would reach it without the link back to the post;
     * its preview of the link carries the picture anyway. A Reddit link post
     * shows the picture as its thumbnail. A Pinterest pin is made from the
     * picture already.
     */
    public function takesPicture(): bool
    {
        return in_array($this, [self::X, self::Telegram, self::WhatsApp, self::Email], true);
    }
}
