<div>
    @foreach($posts as $post)
        <article>
            {{ $post->title }}
        </article>
    @endforeach
</div>
