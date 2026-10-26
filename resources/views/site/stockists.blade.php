@extends('site.layout')

@section('title', 'Where to buy')
@section('description', 'Shops, delis and markets that stock Mossfield organic milk, yoghurt and cheese.')

@section('content')
    <section class="page-head wrap">
        <p class="eyebrow">Where to buy</p>
        <h1>Stockists</h1>
        @if ($byCounty->isEmpty())
            <p class="lead">Our milk, yoghurt and cheese are sold through independent shops, delis and farmers' markets.</p>
        @else
            <p class="lead">Shops and markets that stock our products, by county.</p>
            <nav class="jump" aria-label="Counties">
                @foreach ($byCounty as $county => $shops)
                    <a href="#county-{{ \Illuminate\Support\Str::slug($county) }}">{{ $county }}</a>
                @endforeach
            </nav>
        @endif
    </section>

    @if ($byCounty->isEmpty())
        <section class="section wrap">
            <div class="notice">
                <h2>Looking for your nearest stockist?</h2>
                <p>We are updating our list of stockists. In the meantime please <a href="contact.html">contact us</a> and we will point you to the nearest shop that carries Mossfield products, or ask your local shop or deli to stock us.</p>
            </div>
        </section>
    @else
        @foreach ($byCounty as $county => $shops)
            <section class="section wrap county" id="county-{{ \Illuminate\Support\Str::slug($county) }}">
                <h2>{{ $county }}</h2>
                <ul class="stockists">
                    @foreach ($shops as $shop)
                        <li class="stockist">
                            <h3 class="stockist__name">
                                @if ($shop->website)
                                    <a href="{{ $shop->website }}" rel="noopener">{{ $shop->name }}</a>
                                @else
                                    {{ $shop->name }}
                                @endif
                            </h3>
                            @if ($shop->address)<p class="stockist__address">{{ $shop->address }}</p>@endif
                            @if ($shop->phone)<p class="stockist__phone"><a href="tel:{{ preg_replace('/\s+/', '', $shop->phone) }}">{{ $shop->phone }}</a></p>@endif
                            <p class="badges">
                                @foreach ($shop->products as $product)
                                    <span class="badge badge--{{ $product }}">{{ ucfirst($product) }}</span>
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
@endsection
