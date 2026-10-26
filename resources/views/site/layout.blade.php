{{--
    Shell for every page of the public website (www.mossfield.ie). Rendered
    by `php artisan site:build` to static files, so: relative links only, no
    @vite / route() / asset() / request() / auth(), and nothing that changes
    between builds (no dates), so the output stays diffable.
--}}
@php
    $canonical = $site['base_url'].'/'.($current === 'index.html' ? '' : $current);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $site['name'] }}</title>
    <meta name="description" content="@yield('description', $site['tagline'])">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site['name'] }}">
    <meta property="og:title" content="@yield('title') · {{ $site['name'] }}">
    <meta property="og:description" content="@yield('description', $site['tagline'])">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $site['base_url'] }}/assets/images/header-6.jpg">
    <meta name="theme-color" content="#2f5a3a">
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    @include('site.partials._nav')
    <main id="main">
        @yield('content')
    </main>
    @include('site.partials._footer')
</body>
</html>
