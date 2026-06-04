<?php

namespace App\Livewire\Posts;

use Illuminate\Contracts\View\View;

final class SourceVoting extends OriginVoting
{
    public function render(): View
    {
        $post = $this->post;
        $currentOrigin = null;

        if ($post !== null && auth()->check()) {
            $currentOrigin = $post->originVotes()
                ->where('user_id', auth()->id())
                ->latest('id')
                ->first()
                ?->origin
                ?->value;
        }

        return view('livewire.posts.source-voting', [
            'post' => $post,
            'currentOrigin' => $currentOrigin,
            'isOwnPost' => $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id(),
            'hasVoted' => $currentOrigin !== null,
            'votingDisabled' => $currentOrigin !== null,
        ]);
    }
}
