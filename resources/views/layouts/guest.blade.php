<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Mossfield') }}</title>
        {{-- Fonts are self-hosted: bundled into app.css from @fontsource (no external requests). --}}

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" style="background: var(--bg); color: var(--ink);">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4">
            <div>
                <x-brand-mark href="/" />
            </div>

            <div class="mf-panel w-full sm:max-w-md mt-6 px-6 py-5 overflow-hidden">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
