@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'mf-error space-y-0.5']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
