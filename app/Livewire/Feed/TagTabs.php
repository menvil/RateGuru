<?php

namespace App\Livewire\Feed;

use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class TagTabs extends Component
{
    #[Modelable]
    public ?string $selected = null;

    public function render(): View
    {
        return view('livewire.feed.tag-tabs', [
            'tags' => Tag::query()->orderBy('name')->limit(5)->get(),
        ]);
    }
}
