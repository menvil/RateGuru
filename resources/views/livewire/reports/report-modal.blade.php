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
            @foreach($this->reasons as $reason)
                <span>{{ $reason['label'] }}</span>
            @endforeach
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
