<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Mossfield') }} · Picking</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600|fraunces:500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- Stripped phone-first chrome: no sidebar/topbar — the picking flow is
         one-handed and full-screen. Office/admin get the same column, centred. --}}
    <body class="font-sans antialiased" style="background: var(--bg); color: var(--ink);">
        <div class="mob-shell">
            @if (session('success') || $errors->any())
                <div class="mob-section" style="padding-top: 12px;">
                    @if (session('success'))
                        <div class="mf-flash mf-flash-success" style="margin-bottom: 0;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0"><path d="M20 6L9 17l-5-5"/></svg>
                            <div>{{ session('success') }}</div>
                        </div>
                    @endif
                    @foreach ($errors->all() as $error)
                        <div class="mf-flash mf-flash-error" style="margin-bottom: 0; margin-top: 8px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                            <div>{{ $error }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{ $slot }}
        </div>
    </body>
</html>
