<?php

namespace App\View\Components;

use App\Support\View\AppLayoutData;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  bool  $focused  a page that draws its own bar, such as a game's: the site's header, navigation and search are left out, the sign-in dialog and the toasts stay
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
