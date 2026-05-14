<?php

namespace App\View\Components\Ui;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class BinaryChoice extends Component
{
    public string $inactiveClass = 'border-rg-border2 bg-transparent text-rg-text2';

    public function __construct(
        public string $selected = 'homemade',
        public array $options = [],
        public string $name = 'binary_choice',
    ) {
        $this->options = $this->options === [] ? $this->getDefaultOptions() : $this->options;
    }

    public function getDefaultOptions(): array
    {
        return [
            [
                'label' => 'Homemade',
                'value' => 'homemade',
                'activeClass' => 'border-rg-goodBorder bg-rg-goodSoft text-rg-good',
            ],
            [
                'label' => 'Restaurant',
                'value' => 'restaurant',
                'activeClass' => 'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2',
            ],
        ];
    }

    public function render(): View|Closure|string
    {
        return view('components.ui.binary-choice');
    }
}
