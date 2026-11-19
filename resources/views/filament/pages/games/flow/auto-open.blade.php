{{-- Whether the post the selected step sends the visitor to opens by itself. --}}
<x-admin.ui.switch label="Open the post by itself" id="rg-admin-step-auto-open" :checked="(bool) ($step['settings']['auto_open'] ?? false)" :on-hint="$hint" :off-hint="$hint" wire:model.live="{{ $path }}.settings.auto_open" />
