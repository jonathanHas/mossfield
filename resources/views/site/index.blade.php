@extends('site.layout')

@section('title', 'Organic milk, yoghurt and cheese')
@section('description', 'Mossfield Organic Farm, Clareen, Birr, Co. Offaly: organic milk that is not homogenised, natural yoghurt with nothing added, and handmade farmhouse cheese.')

@section('content')
    <section class="hero">
        <img class="hero__img" src="assets/images/header-6.jpg" alt="A wheel of Mossfield Organic Farm cheese with its printed label" width="800" height="220">
        <div class="hero__body wrap">
            <p class="eyebrow">Clareen, Birr, Co. Offaly</p>
            <h1>Organic milk, yoghurt and farmhouse cheese</h1>
            <p class="lead">Made on our farm at the foot of the Slieve Bloom mountains, from the milk of our own herd.</p>
            <p class="hero__actions">
                <a class="btn btn--primary" href="products.html">Our products</a>
                <a class="btn" href="stockists.html">Where to buy</a>
            </p>
        </div>
    </section>

    <section class="section wrap split">
        <figure class="split__figure">
            <img src="assets/images/ralph-cows.jpg" alt="Ralph Haslam walking among the cows at Mossfield" width="303" height="237">
        </figure>
        <div class="split__text">
            <p class="eyebrow">The farm</p>
            <h2>Farming at Mossfield since 1970, organic since 1999</h2>
            <p>Mossfield Organic Farm is six miles from Birr in County Offaly, at the foot of the Slieve Bloom mountains. Most of the farm is limestone pasture, which grows lush grass woven through with wild herbs and clover: ideal grazing for our herd of eighty cows.</p>
            <p>Ralph Haslam has farmed at Mossfield since 1970 and converted to organic farming in 1999. That was the first of many steps that led to making our own milk, yoghurt, buttermilk and cheese here on the farm, using the freshest possible milk and the least processing we can get away with.</p>
        </div>
    </section>

    <section class="section section--tint">
        <div class="wrap">
            <p class="eyebrow">What we make</p>
            <h2>As close to the cow as we can keep it</h2>
            <div class="cards cards--3">
                @foreach (['milk' => 'Milk', 'yoghurt' => 'Yoghurt', 'cheese' => 'Cheese'] as $type => $label)
                    @php $first = $products->firstWhere('type', $type); @endphp
                    <a class="card card--link" href="products.html#{{ $type }}">
                        @if ($first && isset($productImages[$first->slug]))
                            <img class="card__img" src="{{ $productImages[$first->slug] }}" alt="" loading="lazy">
                        @else
                            <div class="card__img card__img--{{ $type }}" aria-hidden="true"></div>
                        @endif
                        <div class="card__body">
                            <h3>{{ $label }}</h3>
                            <p>
                                @switch($type)
                                    @case('milk') Not homogenised, not standardised. Simply pasteurised and bottled with all the cream. @break
                                    @case('yoghurt') Milk and live cultures, nothing else. Thick, creamy and mild. @break
                                    @default Handmade in small batches and ripened on the farm. Plain, herbed or spiced. @break
                                @endswitch
                            </p>
                            <span class="card__more">See the range</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section wrap callouts">
        <div class="callout">
            <h2>Where to buy</h2>
            <p>Our milk, yoghurt and cheese are sold through independent shops, delis and farmers' markets.</p>
            <a class="btn" href="stockists.html">Find a stockist</a>
        </div>
        <div class="callout">
            <h2>Award-winning cheese</h2>
            <p>Gold at the International Cheese Awards and the Waitrose Trophy for best cheese in the Specialist Cheesemakers section.</p>
            <a class="btn" href="awards.html">See our awards</a>
        </div>
    </section>
@endsection
