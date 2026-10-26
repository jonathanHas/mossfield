@extends('site.layout')

@section('title', 'Awards')
@section('description', 'Awards won by Mossfield Organic Farm cheese, including gold at the International Cheese Awards and the Waitrose Trophy.')

@section('content')
    <section class="page-head wrap">
        <p class="eyebrow">Awards</p>
        <h1>Recognition for our cheese</h1>
        <p class="lead">Our farmhouse cheeses have been judged against the best in Ireland and Britain.</p>
    </section>

    <section class="section wrap">
        <div class="cards cards--3">
            @foreach ($awards as $award)
                <article class="card">
                    @if (! empty($award['image']))
                        <img class="card__img card__img--contain" src="assets/images/{{ $award['image'] }}" alt="{{ $award['title'] }}, {{ $award['event'] }}" loading="lazy">
                    @endif
                    <div class="card__body">
                        <p class="eyebrow">{{ $award['event'] }}</p>
                        <h2 class="card__title">{{ $award['title'] }}</h2>
                        <p>{{ $award['detail'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endsection
