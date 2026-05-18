<div data-testid="post-show" class="mx-auto w-full max-w-2xl">
    <section>
        <h1 class="text-2xl font-bold text-rg-text sm:text-3xl">{{ $post->title }}</h1>

        @if($post->description)
            <p class="mt-3 text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
        @endif
    </section>
</div>
