<?php

namespace App\Support\VisualRegression;

use InvalidArgumentException;

final class VisualScreenshotTargets
{
    /**
     * @return array<string, VisualScreenshotTarget>
     */
    public function all(): array
    {
        return [
            'feed-desktop' => new VisualScreenshotTarget(
                name: 'feed-desktop',
                routeName: 'feed',
                waitSelector: '[data-testid="feed-page"]',
                viewportWidth: 1440,
                viewportHeight: 1000,
                outputFile: 'feed-desktop.png',
            ),
            'feed-mobile' => new VisualScreenshotTarget(
                name: 'feed-mobile',
                routeName: 'feed',
                waitSelector: '[data-testid="feed-page"]',
                viewportWidth: 390,
                viewportHeight: 844,
                outputFile: 'feed-mobile.png',
            ),
            'upload-modal' => new VisualScreenshotTarget(
                name: 'upload-modal',
                routeName: 'feed',
                waitSelector: '[data-testid="feed-page"]',
                viewportWidth: 1440,
                viewportHeight: 1000,
                outputFile: 'upload-modal.png',
                authenticated: true,
                clickSelector: '[data-testid="open-upload-button"]',
                afterClickWaitSelector: '[data-testid="upload-modal"]',
            ),
            'post-drawer' => new VisualScreenshotTarget(
                name: 'post-drawer',
                routeName: 'feed',
                waitSelector: '[data-testid="feed-page"]',
                viewportWidth: 1440,
                viewportHeight: 1000,
                outputFile: 'post-drawer.png',
                clickSelector: '[data-testid="post-card"]',
                afterClickWaitSelector: '[data-testid="post-drawer"]',
            ),
            'post-show' => new VisualScreenshotTarget(
                name: 'post-show',
                routeName: 'posts.show',
                waitSelector: '[data-testid="post-show"]',
                viewportWidth: 1440,
                viewportHeight: 1000,
                outputFile: 'post-show.png',
                routeModel: 'post',
            ),
        ];
    }

    public function get(string $name): VisualScreenshotTarget
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException("Unknown visual screenshot target [{$name}].");
    }

    /**
     * @return list<VisualScreenshotTarget>
     */
    public function resolve(string $name): array
    {
        if ($name === 'all') {
            return array_values($this->all());
        }

        return [$this->get($name)];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
