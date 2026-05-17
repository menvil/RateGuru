<div class="grid gap-4">
    @if($posts->isEmpty())
        <x-ui.empty-state
            title="No dishes yet"
            description="Published dishes will appear here."
        />
    @else
        @foreach($posts as $post)
            <x-feed.post-card :post="$post" :key="$post->id" />
        @endforeach
    @endif
</div>
