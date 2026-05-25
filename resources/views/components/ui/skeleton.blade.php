@props([
    'shape' => 'line',
    'width' => null,
    'height' => null,
])

@php
    $shapeClasses = [
        'line' => [
            'shape' => 'rounded',
            'width' => 'w-full',
            'height' => 'h-3',
        ],
        'block' => [
            'shape' => 'rounded-md',
            'width' => 'w-full',
            'height' => 'h-24',
        ],
        'circle' => [
            'shape' => 'rounded-full',
            'width' => 'w-10',
            'height' => 'h-10',
        ],
    ][$shape] ?? [
        'shape' => 'rounded',
        'width' => 'w-full',
        'height' => 'h-3',
    ];
@endphp

<div
    aria-hidden="true"
    {{ $attributes->merge([
        'class' => trim(implode(' ', [
            'animate-pulse border border-white/10 bg-white/10 motion-safe:transition-opacity motion-safe:duration-200 motion-reduce:transition-none',
            $shapeClasses['shape'],
            $width ?? $shapeClasses['width'],
            $height ?? $shapeClasses['height'],
        ])),
    ]) }}
></div>
