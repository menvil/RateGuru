<?php

namespace App\View\Components;

use App\Support\View\AppLayoutData;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  bool  $focused  a page that keeps the header and leaves out the navigation around it, such as a game's
     */
    public function __construct(public bool $focused = false) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app', [...app(AppLayoutData::class)->toArray(), 'focused' => $this->focused]);
    }
}
