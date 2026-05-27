@props([
    'label',
    'value',
    'sub' => null,
    'tone' => null,
    'align' => 'left',
])

@php
    $valueColor = match($tone) {
        'warn' => 'var(--warn-ink)',
        'danger' => 'var(--danger)',
        'accent' => 'var(--accent-ink)',
        default => 'var(--ink)',
    };
    $alignClass = $align === 'right' ? 'text-right' : '';
@endphp

<div class="{{ $alignClass }}">
    <div class="text-[10.5px] font-medium uppercase" style="color: var(--muted); letter-spacing: 0.5px;">
        {{ $label }}
    </div>
    <div class="flex items-baseline gap-1.5 mt-1 {{ $align === 'right' ? 'justify-end' : '' }}">
        <div class="text-[28px] font-semibold font-mono" style="color: {{ $valueColor }}; letter-spacing: -0.6px;">
            {{ $value }}
        </div>
        @if(!is_null($sub) && $sub !== '')
            <div class="text-[12px] font-mono" style="color: var(--muted);">{{ $sub }}</div>
        @endif
    </div>
</div>
