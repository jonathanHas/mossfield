{{--
    Standalone error page (F-41). Not x-app-layout: errors happen to guests
    too, and an unmatched URL 404s before the web middleware runs, so there
    may be no session and no signed-in user. Offers exactly one way back:
    factory → picking, any other signed-in user → dashboard, guest → login.
--}}
@php
    $user = auth()->user();
    [$homeUrl, $homeLabel] = match (true) {
        $user === null => [route('login'), 'Sign in'],
        $user->isFactory() => [route('picking.index'), 'Back to picking'],
        default => [route('dashboard'), 'Back to the dashboard'],
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') · {{ config('app.name', 'Mossfield') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased" style="background: var(--bg); color: var(--ink);">
        <div class="min-h-screen flex items-center justify-center px-4">
            <div class="mf-panel w-full max-w-[420px] p-8 text-center">
                <div class="font-mono text-[12px]" style="color: var(--muted);">@yield('code')</div>
                <h1 class="mt-2 text-[20px] font-display font-medium" style="letter-spacing: -0.3px;">@yield('message')</h1>
                <div class="mt-2 font-mono text-[11.5px] break-all" style="color: var(--muted);" data-error-path>/{{ ltrim(request()->path(), '/') }}</div>
                <div class="mt-6">
                    <a href="{{ $homeUrl }}" class="mf-btn-primary">{{ $homeLabel }}</a>
                </div>
            </div>
        </div>
    </body>
</html>
