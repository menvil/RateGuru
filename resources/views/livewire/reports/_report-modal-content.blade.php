@if($submitted)
    <div data-testid="report-success">
        <x-ui.empty-state
            title="{{ __('ui.report.submitted_title') }}"
            description="{{ __('ui.report.submitted_description') }}"
        />
    </div>
@else
    <form data-testid="report-form" wire:submit.prevent="submit" class="space-y-4">
        @error('report')
            <div data-testid="report-submit-error">
                <x-ui.error-message
                    title="{{ __('ui.report.error_title') }}"
                    :message="$message"
                />
            </div>
        @enderror

        <fieldset data-testid="report-reason-selector" class="space-y-2">
            <legend class="text-xs font-semibold uppercase tracking-wide text-rg-muted">
                {{ __('ui.report.reason_label') }}
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

            @error('reason')
                <p data-testid="report-reason-error" class="text-xs text-rg-dangerText">
                    {{ $message }}
                </p>
            @enderror
        </fieldset>

        <div class="space-y-2">
            <label for="report-message" class="block text-xs font-semibold uppercase tracking-wide text-rg-muted">
                {{ __('ui.report.optional_details_label') }}
            </label>

            <x-ui.textarea
                id="report-message"
                name="message"
                wire:model.defer="message"
                rows="4"
                maxlength="1000"
                placeholder="{{ __('ui.report.optional_placeholder') }}"
            />

            <p class="text-xs text-rg-muted">
                {{ __('ui.report.optional_hint') }}
            </p>

            @error('message')
                <p data-testid="report-message-error" class="text-xs text-rg-dangerText">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div class="flex justify-end">
            <x-ui.button
                type="submit"
                size="sm"
                data-testid="submit-report"
                wire:loading.attr="disabled"
                wire:target="submit"
            >
                <span wire:loading.remove wire:target="submit">{{ __('ui.report.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('ui.report.submitting') }}</span>
            </x-ui.button>
        </div>
    </form>
@endif
