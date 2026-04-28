@props([
    'label',
    'value',
    'sub' => null,
    'tone' => null,
])

@php
    $subColor = $tone === 'warn' ? 'var(--warn-ink)' : ($tone === 'danger' ? 'var(--danger)' : 'var(--muted)');
@endphp

<div class="mf-panel px-4 py-3.5">
    <div class="text-[11.5px] font-medium uppercase" style="color: var(--muted); letter-spacing: 0.2px;">
        {{ $label }}
    </div>
    <div class="flex items-baseline gap-2 mt-1.5">
        <div class="text-[26px] font-semibold" style="color: var(--ink); letter-spacing: -0.6px;">
            {{ $value }}
        </div>
        @if (! is_null($sub) && $sub !== '')
            <div class="text-[12px] font-mono" style="color: {{ $subColor }};">{{ $sub }}</div>
        @endif
    </div>
</div>
