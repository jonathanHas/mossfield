@extends('site.layout')

@section('title', 'Contact')
@section('description', 'Contact Mossfield Organic Farm, Clareen, Birr, Co. Offaly.')

@php $c = $site['contact']; @endphp

@section('content')
    <section class="page-head wrap">
        <p class="eyebrow">Contact</p>
        <h1>Get in touch</h1>
        <p class="lead">Questions about our products, where to buy them, or stocking them in your shop: we would love to hear from you.</p>
    </section>

    <section class="section wrap contact">
        <div class="contact__block">
            <h2>Email</h2>
            <p><a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a></p>
        </div>
        <div class="contact__block">
            <h2>Phone</h2>
            <p>Farm: <a href="tel:{{ preg_replace('/\s+/', '', $c['phone']) }}">{{ $c['phone'] }}</a></p>
            <p>Office: <a href="tel:{{ preg_replace('/\s+/', '', $c['office']) }}">{{ $c['office'] }}</a></p>
        </div>
        <div class="contact__block">
            <h2>Address</h2>
            <address>
                @foreach ($c['address'] as $line)
                    {{ $line }}@if (! $loop->last)<br>@endif
                @endforeach
            </address>
            <p><a href="{{ $c['map'] }}" rel="noopener">Open in Google Maps</a></p>
        </div>
        <div class="contact__block">
            <h2>Follow</h2>
            <p><a href="{{ $c['facebook'] }}" rel="noopener">Mossfield Organic Cheese on Facebook</a></p>
        </div>
    </section>
@endsection
