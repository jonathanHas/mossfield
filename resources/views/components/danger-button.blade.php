<button {{ $attributes->merge(['type' => 'submit', 'class' => 'mf-btn-danger']) }}>
    {{ $slot }}
</button>
