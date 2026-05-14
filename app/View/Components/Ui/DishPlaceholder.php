<?php

namespace App\View\Components\Ui;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class DishPlaceholder extends Component
{
    public function __construct(
        public string $palette = 'carbonara',
        public string $label = 'DISH PREVIEW',
        public string $ratio = 'feed',
    ) {}

    public function palettes(): array
    {
        return [
            'carbonara' => [
                'from' => 'var(--rg-food-carbonara-1)',
                'mid' => 'var(--rg-food-carbonara-2)',
                'to' => 'var(--rg-food-carbonara-3)',
            ],
            'matcha' => [
                'from' => 'var(--rg-food-matcha-1)',
                'mid' => 'var(--rg-food-matcha-2)',
                'to' => 'var(--rg-food-matcha-3)',
            ],
            'ramen' => [
                'from' => '#2f120d',
                'mid' => '#8a2d18',
                'to' => '#e08b3e',
            ],
            'avocado' => [
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
        return view('components.ui.dish-placeholder', [
            'placeholderRatioClass' => $this->ratioClass(),
            'placeholderColors' => $this->colors(),
        ]);
    }
}
