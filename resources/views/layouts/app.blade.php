<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Mossfield') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600|fraunces:500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" style="background: var(--app-bg); color: var(--ink);">
        <div x-data="{ open: false }" class="min-h-screen flex">
            <!-- Sidebar (desktop) -->
            <div class="hidden md:flex flex-shrink-0">
                @include('layouts.navigation')
            </div>

            <!-- Sidebar (mobile drawer) -->
            <div x-show="open" x-cloak class="md:hidden fixed inset-0 z-40 flex" style="display: none;">
                <div class="fixed inset-0 bg-black/30" @click="open = false"></div>
                <div class="relative z-50 flex">
                    @include('layouts.navigation')
                </div>
            </div>

            <!-- Main column -->
            <div class="flex-1 flex flex-col min-w-0 min-h-screen">
                @include('layouts.partials.topbar')

                <main class="flex-1" style="background: var(--bg);">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
