<?php

namespace App\Support\Sharing;

use App\Enums\ShareProvider;

final class ShareUrlBuilder
{
    public function build(string|ShareProvider $provider, ShareMetadata $metadata): ?string
    {
        $provider = is_string($provider) ? ShareProvider::from($provider) : $provider;

        return match ($provider) {
            ShareProvider::Facebook => $this->facebook($metadata),
            ShareProvider::X => $this->x($metadata),
            ShareProvider::Telegram => $this->telegram($metadata),
            ShareProvider::WhatsApp => $this->whatsapp($metadata),
            ShareProvider::Reddit => $this->reddit($metadata),
            ShareProvider::Pinterest => $this->pinterest($metadata),
            ShareProvider::Email => $this->email($metadata),
            ShareProvider::CopyLink, ShareProvider::Native => null,
        };
    }

    private function facebook(ShareMetadata $metadata): string
    {
        return 'https://www.facebook.com/sharer/sharer.php?u='.urlencode($metadata->url);
    }

    private function x(ShareMetadata $metadata): string
    {
        return 'https://twitter.com/intent/tweet?'.http_build_query([
            'url' => $metadata->url,
            'text' => $metadata->title,
        ]);
    }

    private function telegram(ShareMetadata $metadata): string
    {
        return 'https://t.me/share/url?'.http_build_query([
            'url' => $metadata->url,
            'text' => $metadata->title,
        ]);
    }

    private function whatsapp(ShareMetadata $metadata): string
    {
        return 'https://wa.me/?text='.urlencode($metadata->title.' '.$metadata->url);
    }

    private function reddit(ShareMetadata $metadata): string
    {
        return 'https://www.reddit.com/submit?'.http_build_query([
            'url' => $metadata->url,
            'title' => $metadata->title,
        ]);
    }

    private function pinterest(ShareMetadata $metadata): ?string
    {
        if ($metadata->imageUrl === null) {
            return null;
        }

        return 'https://pinterest.com/pin/create/button/?'.http_build_query([
            'url' => $metadata->url,
            'media' => $metadata->imageUrl,
            'description' => $metadata->description,
        ]);
    }

    private function email(ShareMetadata $metadata): string
    {
        return 'mailto:?'.http_build_query([
            'subject' => $metadata->title,
            'body' => $metadata->description.' '.$metadata->url,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
