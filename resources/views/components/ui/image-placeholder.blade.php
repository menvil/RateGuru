@props([
    'label',
    'ratio' => 'square',
    'palette' => 'neutral',
])

<x-ui.dish-placeholder
    :label="$label"
    :ratio="$ratio"
    :palette="$palette"
    {{ $attributes }}
/>
