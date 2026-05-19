<div
    data-testid="report-modal"
    x-data="{ reportOpen: false }"
    @keydown.escape.window="reportOpen = false"
>
    <button
        type="button"
        data-testid="open-report-modal"
        @click="reportOpen = true"
        class="text-xs font-semibold text-rg-muted transition hover:text-rg-dangerText"
    >
        Report
    </button>

    <x-ui.modal title="Report content" state="reportOpen">
        <div class="space-y-4">
            <fieldset data-testid="report-reason-selector" class="space-y-2">
                <legend class="text-xs font-semibold uppercase tracking-wide text-rg-muted">
                    Reason
                </legend>

                <div class="grid gap-2">
                    @foreach($this->reasons as $reason)
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 py-2 text-sm text-rg-text transition has-[:checked]:border-rg-accent has-[:checked]:bg-rg-accentSoft hover:border-rg-accent"
                        >
                            <input
                                type="radio"
                                name="reason"
                                value="{{ $reason['value'] }}"
                                wire:model.live="reason"
                                class="size-4 accent-rg-accent"
                            >
                            <span>{{ $reason['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="space-y-2">
                <label for="report-message" class="block text-xs font-semibold uppercase tracking-wide text-rg-muted">
                    Optional details
                </label>

                <x-ui.textarea
                    id="report-message"
                    name="message"
                    wire:model.defer="message"
                    rows="4"
                    maxlength="1000"
                    placeholder="Add context for moderators..."
                />

                <p class="text-xs text-rg-muted">
                    Optional. Max 1000 characters.
                </p>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button
                type="button"
                variant="secondary"
                size="sm"
                data-testid="close-report-modal"
                @click="reportOpen = false"
            >
                Close
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
