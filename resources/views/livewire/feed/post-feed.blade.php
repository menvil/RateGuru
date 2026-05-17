<div class="grid gap-4">
    @foreach($posts as $post)
        <x-feed.post-card :post="$post" :key="$post->id" />
    @endforeach
</div>
