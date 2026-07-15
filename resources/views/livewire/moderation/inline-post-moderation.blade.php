<div data-testid="inline-post-moderation">
    @if ($this->canModerate)
        @if ($variant === 'menu')
            <div x-data="{ compactHideOpen: false }" data-testid="inline-post-moderation-menu">
                @if ($this->adminPostUrl)
                    <a
                        href="{{ $this->adminPostUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        {{ __('ui.moderation.open_in_admin') }}
                    </a>
                @endif

                @if ($this->canApprove)
                    <button
                        type="button"
                        wire:click="approve"
                        data-testid="moderation-approve"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        {{ __('ui.moderation.approve') }}
                    </button>

                    <button
                        type="button"
                        wire:click="reject"
                        data-testid="moderation-reject"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                    >
                        {{ __('ui.moderation.reject') }}
                    </button>
                @endif

                @if ($this->canHide)
                    <button
                        type="button"
                        x-on:click="compactHideOpen = true"
                        data-testid="moderation-hide"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                    >
                        {{ __('ui.moderation.hide') }}
                    </button>

                    <x-ui.modal title="{{ __('ui.moderation.hide_confirm_title') }}" state="compactHideOpen">
                        <p class="text-sm text-rg-text2">
                            {{ __('ui.moderation.hide_confirm_description') }}
                        </p>

                        <x-slot:footer>
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                x-on:click="compactHideOpen = false"
                                data-testid="hide-confirmation-cancel"
                            >
                                {{ __('ui.actions.cancel') }}
                            </x-ui.button>

                            <x-ui.button
                                type="button"
                                variant="danger"
                                wire:click="hide"
                                x-on:click="compactHideOpen = false"
                                data-testid="hide-confirmation-confirm"
                            >
                                {{ __('ui.moderation.confirm_hide') }}
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                @endif

                @if ($this->canRestore)
                    <button
                        type="button"
                        wire:click="restore"
                        data-testid="moderation-restore"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        {{ __('ui.moderation.restore') }}
                    </button>
                @endif
            </div>
        @else
        <div
            x-data="{ confirmHideOpen: false }"
            data-testid="inline-post-moderation-panel"
            class="mt-3 rounded-md border border-rg-border2 bg-rg-card2 p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <x-ui.badge>{{ __('ui.moderation.badge') }}</x-ui.badge>

                <div data-testid="open-in-admin-link" class="text-xs">
                    @if ($this->adminPostUrl)
                        <a
                            href="{{ $this->adminPostUrl }}"
                            target="_blank"
                            rel="noopener"
                            class="text-rg-text2 hover:text-rg-text"
                        >
                            {{ __('ui.moderation.open_in_admin') }}
                        </a>
                    @else
                        <span class="text-rg-muted">
                            {{ __('ui.moderation.open_in_admin') }}
                        </span>
                    @endif
                </div>
            </div>

            @if ($error)
                <p data-testid="moderation-error" class="mt-2 text-xs text-rg-danger">
                    {{ $error }}
                </p>
            @endif

            @if ($success)
                <p data-testid="moderation-success" class="mt-2 text-xs text-rg-text2">
                    {{ $success }}
                </p>
            @endif

            <div class="mt-2">
                <label for="moderation-reason-{{ $post->id }}" class="block text-xs text-rg-text2">
                    {{ __('ui.moderation.reason') }}
                </label>
                <x-ui.textarea
                    name="moderation_reason"
                    id="moderation-reason-{{ $post->id }}"
                    rows="2"
                    maxlength="1000"
                    placeholder="{{ __('ui.moderation.reason_placeholder') }}"
                    wire:model.defer="reason"
                    data-testid="moderation-reason-input"
                />
            </div>

            <div class="mt-2 flex flex-wrap gap-2">
                @if ($this->canApprove)
                    <x-ui.button
                        type="button"
                        wire:click="approve"
                        data-testid="moderation-approve"
                    >
                        {{ __('ui.moderation.approve') }}
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        variant="danger"
                        wire:click="reject"
                        data-testid="moderation-reject"
                    >
                        {{ __('ui.moderation.reject') }}
                    </x-ui.button>
                @endif

                @if ($this->canHide)
                    <x-ui.button
                        type="button"
                        variant="danger"
                        x-on:click="confirmHideOpen = true"
                        data-testid="moderation-hide"
                    >
                        {{ __('ui.moderation.hide') }}
                    </x-ui.button>
                @endif

                @if ($this->canRestore)
                    <x-ui.button
                        type="button"
                        wire:click="restore"
                        data-testid="moderation-restore"
                    >
                        {{ __('ui.moderation.restore') }}
                    </x-ui.button>
                @endif
            </div>

            @if ($this->canHide)
                <div data-testid="hide-confirmation-modal">
                    <x-ui.modal title="{{ __('ui.moderation.hide_confirm_title') }}" state="confirmHideOpen">
                        <p class="text-sm text-rg-text2">
                            {{ __('ui.moderation.hide_confirm_description') }}
                        </p>

                        <x-slot:footer>
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                x-on:click="confirmHideOpen = false"
                                data-testid="hide-confirmation-cancel"
                            >
                                {{ __('ui.actions.cancel') }}
                            </x-ui.button>

                            <x-ui.button
                                type="button"
                                variant="danger"
                                wire:click="hide"
                                x-on:click="confirmHideOpen = false"
                                data-testid="hide-confirmation-confirm"
                            >
                                {{ __('ui.moderation.confirm_hide') }}
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                </div>
            @endif
        </div>
        @endif
    @endif
</div>
