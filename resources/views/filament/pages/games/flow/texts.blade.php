{{--
    Texts of the selected step, those named in $columns and in that order:
    each labelled as its kind of step uses it, multi-line where it is a body,
    with its limit, its hint and its error.
--}}
@foreach ($columns as $column)
    @continue (! isset($stepTexts[$column]))
    @php
        $text = $stepTexts[$column];
        $multiline = in_array($column, ['body', 'notice', 'consent_label'], true) && ! in_array($column, $singleLine[$stepType->value] ?? [], true);
        $hint = $hints[$stepType->value][$column] ?? null;
        $required = in_array($column, $stepRequired, true) ? null : false;
        $error = $errors->first($path.'.texts.'.$column) ?: null;
    @endphp
    @if ($multiline)
        <x-admin.ui.textarea :label="$text['label']" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :rows="3" :limit="$textLimits[$column]" :required="$required" :hint="$hint" :error="$error" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
    @else
        <x-admin.ui.text-field :label="$text['label']" :required="$required" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :hint="$hint" :error="$error" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
    @endif
@endforeach
