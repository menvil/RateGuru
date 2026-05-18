<?php

namespace App\Livewire\Feed;

use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class CategoryTabs extends Component
{
    #[Modelable]
    public ?string $selected = null;

    public function render(): View
    {
        return view('livewire.feed.category-tabs', [
            'tags' => Tag::query()->orderBy('name')->limit(10)->get(),
        ]);
    }
}
