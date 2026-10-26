<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="brand" href="index.html">
            <span class="brand__name">Mossfield</span>
            <span class="brand__sub">Organic Farm</span>
        </a>
        <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true" tabindex="-1">
        <label for="nav-toggle" class="nav-burger" aria-label="Menu"><span></span></label>
        <nav class="nav" aria-label="Main">
            <ul>
                @foreach ($site['pages'] as $file => $label)
                    <li><a href="{{ $file }}"@if ($file === $current) aria-current="page"@endif>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
    </div>
</header>
