@extends('site.layout')

@section('title', 'Products')
@section('description', 'Organic milk, natural yoghurt and handmade farmhouse cheese from Mossfield Organic Farm, Co. Offaly.')

@php
    $types = [
        'milk' => ['Milk', 'Most milk is standardised, homogenised and pasteurised before it reaches the shop. Ours is simply pasteurised and bottled, with all the cream left in.'],
        'yoghurt' => ['Yoghurt', 'Milk and live cultures, nothing else. Made in small batches, it has a thick, creamy texture and a mild tang.'],
        'cheese' => ['Cheese', 'Handmade in small batches on the farm from our own milk, and ripened here. All our cheeses are suitable for vegetarians.'],
    ];
@endphp

@section('content')
    <section class="page-head wrap">
        <p class="eyebrow">Products</p>
        <h1>Made on the farm, from our own milk</h1>
        <p class="lead">We keep the processing to the minimum so you taste the milk, not the factory.</p>
        <nav class="jump" aria-label="Product types">
            @foreach ($types as $type => [$label])
                <a href="#{{ $type }}">{{ $label }}</a>
            @endforeach
        </nav>
    </section>

    @foreach ($types as $type => [$label, $intro])
        <section class="section wrap product-group" id="{{ $type }}">
            <header class="product-group__head">
                <h2>{{ $label }}</h2>
                <p>{{ $intro }}</p>
            </header>
            @if (($byType[$type] ?? collect())->isEmpty())
                <p class="muted">Details coming soon.</p>
            @else
                <div class="products">
                    @foreach ($byType[$type] as $product)
                        <article class="product" id="{{ $product->slug }}">
                            @if (isset($productImages[$product->slug]))
                                <img class="product__img" src="{{ $productImages[$product->slug] }}" alt="{{ $product->name }}" loading="lazy">
                            @else
                                <div class="product__img product__img--{{ $product->type }}" aria-hidden="true"></div>
                            @endif
                            <div class="product__body">
                                <h3>{{ $product->name }}</h3>
                                @foreach ($product->paragraphs() as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
@endsection
