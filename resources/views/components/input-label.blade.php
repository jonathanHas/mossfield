@props(['value'])

<label {{ $attributes->merge(['class' => 'mf-label']) }}>
    {{ $value ?? $slot }}
</label>
