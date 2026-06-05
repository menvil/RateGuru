<?php

namespace App\Livewire\Posts;

final class SourceVoting extends OriginVoting
{
    protected string $viewName = 'livewire.posts.source-voting';

    protected string $votedEventName = 'source-voted';
}
