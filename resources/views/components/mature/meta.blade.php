@props(['label', 'value'])

<div>
    <div style="font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">{{ $label }}</div>
    <div class="font-mono" style="font-size: 12.5px; font-weight: 500; margin-top: 2px;">{{ $value }}</div>
</div>
