<?php

namespace App\View\Components\Sharing;

use App\Enums\ShareProvider;
use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ShareButtons extends Component
{
    public ShareMetadata $metadata;

    /** @var array<string, string|null> */
    public array $providerUrls;

    public function __construct(
        public Post $post,
        PostShareMetadata $postShareMetadata,
        ShareUrlBuilder $urlBuilder,
    ) {
        $this->metadata = $postShareMetadata->forPost($post);
        $this->providerUrls = $this->buildProviderUrls($urlBuilder);
    }

    public function render(): View
    {
        return view('components.sharing.share-buttons');
    }

    /** @return array<string, string|null> */
    private function buildProviderUrls(ShareUrlBuilder $urlBuilder): array
    {
        $urls = [];

        foreach (ShareProvider::urlProviders() as $provider) {
            $urls[$provider->value] = $urlBuilder->build($provider, $this->metadata);
        }

        return $urls;
    }
}
