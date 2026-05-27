@props([
    'tone' => 'neutral',
    'dot' => false,
])

@php
    $toneClass = match($tone) {
        'accent' => 'mf-tag-accent',
        'warn' => 'mf-tag-warn',
        'danger' => 'mf-tag-danger',
        'milk' => 'mf-tag-milk',
        'yog' => 'mf-tag-yog',
        default => 'mf-tag-neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => 'mf-tag '.$toneClass]) }}>
    @if($dot)
        <span class="inline-block w-1.5 h-1.5 rounded-full" style="background: currentColor; opacity: 0.85;"></span>
    @endif
    {{ $slot }}
</span>
