@php $c = $site['contact']; @endphp
<footer class="site-footer">
    <div class="wrap site-footer__grid">
        <div>
            <p class="site-footer__brand">Mossfield Organic Farm</p>
            <p class="muted">{{ $site['tagline'] }}</p>
        </div>
        <address>
            @foreach ($c['address'] as $line)
                {{ $line }}@if (! $loop->last)<br>@endif
            @endforeach
        </address>
        <div>
            <p><a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a></p>
            <p><a href="tel:{{ preg_replace('/\s+/', '', $c['phone']) }}">{{ $c['phone'] }}</a></p>
            <p><a href="{{ $c['facebook'] }}" rel="noopener">Find us on Facebook</a></p>
        </div>
    </div>
    <div class="wrap site-footer__small">
        <p>&copy; Mossfield Organic Farm, Clareen, Birr, Co. Offaly. Organic milk, yoghurt and cheese made on the farm.</p>
    </div>
</footer>
