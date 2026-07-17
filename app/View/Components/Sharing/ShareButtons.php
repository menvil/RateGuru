<?php

namespace App\View\Components\Sharing;

use App\Enums\ShareProvider;
use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

final class ShareButtons extends Component
{
    public ShareMetadata $metadata;

    /** @var array<string, array<string, mixed>> */
    public array $enabledProviders;

    /** @var list<string> */
    public array $socialProviderKeys;

    /** @var array<string, string|null> */
    public array $providerUrls;

    /** @var Collection<int, string> */
    public Collection $visibleProviders;

    public bool $hasNative;

    public function __construct(
        public Post $post,
        PostShareMetadata $postShareMetadata,
        ShareUrlBuilder $urlBuilder,
    ) {
        $this->metadata = $postShareMetadata->forPost($post);
        $this->enabledProviders = array_filter(
            config('share.providers', []),
            fn ($p) => $p['enabled'] ?? true
        );
        $this->socialProviderKeys = array_values(array_filter(
            array_keys($this->enabledProviders),
            fn ($key) => ! in_array($key, ['copy_link', 'native'], true)
        ));
        $this->providerUrls = $this->buildProviderUrls($urlBuilder);
        $this->visibleProviders = collect($this->socialProviderKeys)->filter(
            fn ($provider) => isset($this->providerUrls[$provider])
        )->values();
        $this->hasNative = isset($this->enabledProviders['native']);
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
