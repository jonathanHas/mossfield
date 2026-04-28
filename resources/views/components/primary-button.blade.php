<button {{ $attributes->merge(['type' => 'submit', 'class' => 'mf-btn-primary']) }}>
    {{ $slot }}
</button>
