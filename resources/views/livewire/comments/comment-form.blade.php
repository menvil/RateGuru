<div>
    <form
        data-testid="comment-form"
        wire:submit.prevent="submit"
        class="flex min-h-[50px] items-center gap-2.5 rounded-[10px] border border-rg-border2 bg-rg-card2 py-2 pl-3.5 pr-2"
    >
        <label for="comment-body" class="sr-only">{{ __('ui.comments.add_comment') }}</label>

        <div class="min-w-0 flex-1">
            <input
                id="comment-body"
                data-testid="comment-body"
                name="body"
                type="text"
                wire:model="body"
                maxlength="1000"
                placeholder="{{ __('ui.comments.add_comment') }}"
                @class([
                    'rg-comment-input h-8 w-full appearance-none border-0 bg-transparent p-0 text-[13.5px] text-rg-text shadow-none outline-none ring-0 placeholder:text-rg-muted focus:border-0 focus:outline-none focus:ring-0 focus-visible:border-0 focus-visible:outline-none focus-visible:ring-0',
                    'text-rg-dangerText' => $errors->has('body'),
                ])
            >

            @error('body')
                <p data-testid="comment-body-error" class="mt-1 text-xs text-rg-dangerText" role="alert" aria-live="polite">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <x-ui.button
            type="submit"
            data-testid="comment-submit"
            size="sm"
            class="h-8 shrink-0 rounded-rgSm px-4 text-[12.5px]"
            wire:loading.attr="disabled"
            wire:target="submit"
        >
            <span wire:loading.remove wire:target="submit">{{ __('ui.comments.post') }}</span>
            <span wire:loading wire:target="submit">{{ __('ui.comments.posting') }}</span>
        </x-ui.button>
    </form>
</div>
