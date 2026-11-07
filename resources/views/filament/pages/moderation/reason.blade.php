{{--
    The reason a moderation confirmation asks for (FRM-03), bound to the
    page's `reason`: required or optional, counted to its limit, with the
    preset chips when there are any. An irreversible confirmation adds the
    acknowledgement (FRM-07), bound to `acknowledged`.

    @param string $label
    @param bool $required
    @param int $limit
    @param list<string> $presets
    @param string $hint
    @param ?string $acknowledge the acknowledgement's words, or null for none
--}}
<x-admin.ui.textarea
    :label="$label"
    id="rg-admin-moderation-reason"
    :value="$reason"
    :required="$required"
    :limit="$limit"
    :presets="$presets"
    :placeholder="$required ? 'Describe why this is necessary…' : 'Add a note for the moderation log…'"
    :hint="$hint"
    :error="$errors->first('reason') ?: null"
    wire:model="reason"
    :autofocus="$required"
/>

@if ($acknowledge !== null)
    <div>
        <x-admin.ui.checkbox :label="$acknowledge" id="rg-admin-moderation-acknowledge" wire:model="acknowledged" :aria-invalid="$errors->has('acknowledged') ? 'true' : null" />
        @error('acknowledged')
            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
        @enderror
    </div>
@endif
