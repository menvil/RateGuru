<?php

namespace App\View\Components\Ui;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ImagePlaceholder extends Component
{
    public function __construct(
        public string $palette = 'warm',
        public string $label = 'IMAGE PREVIEW',
        public string $ratio = 'square',
    ) {}

    public function palettes(): array
    {
        return [
            'warm' => [
                'from' => 'var(--rg-palette-warm-1)',
                'mid' => 'var(--rg-palette-warm-2)',
                'to' => 'var(--rg-palette-warm-3)',
            ],
            'green' => [
                'from' => 'var(--rg-palette-green-1)',
                'mid' => 'var(--rg-palette-green-2)',
                'to' => 'var(--rg-palette-green-3)',
            ],
            'red' => [
                'from' => '#2f120d',
                'mid' => '#8a2d18',
                'to' => '#e08b3e',
            ],
            'lime' => [
                'from' => '#18320f',
                'mid' => '#4d7c0f',
                'to' => '#bef264',
            ],
            'neutral' => [
                'from' => '#15151f',
                'mid' => '#2d2438',
                'to' => '#6b4c7b',
            ],
        ];
    }

    public function ratios(): array
    {
        return [
            'feed' => 'aspect-[16/10]',
            'detail' => 'aspect-[4/3]',
            'square' => 'aspect-square',
            'portrait' => 'aspect-[3/4]',
            'video' => 'aspect-video',
        ];
    }

    public function ratioClass(): string
    {
        return $this->ratios()[$this->ratio] ?? $this->ratios()['feed'];
    }

    public function colors(): array
    {
        return $this->palettes()[$this->palette] ?? $this->palettes()['neutral'];
    }

    public function render(): View|Closure|string
    {
        return view('components.ui.image-placeholder', [
            'placeholderRatioClass' => $this->ratioClass(),
            'placeholderColors' => $this->colors(),
        ]);
    }
}
