<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    public function render(): View
    {
        return view('livewire.feed.post-drawer', [
            'post' => null,
        ]);
    }
}
