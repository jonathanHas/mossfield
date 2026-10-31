<x-app-layout>
    <x-slot name="header">{{ $isIndex ? 'Help' : 'Help · '.$title }}</x-slot>

    <div class="px-6 py-5">
        @unless ($isIndex)
            <div class="mb-3 text-[13px]">
                <a href="{{ route('help.index') }}" class="mf-link">← All help pages</a>
            </div>
        @endunless

        <div class="mf-panel max-w-3xl">
            {{-- Unescaped on purpose: the source is a Markdown file in the repository
                 (docs/guide/), not user input, and the renderer strips raw HTML
                 (html_input => strip) and unsafe links before it gets here. --}}
            <article class="mf-prose p-6">{!! $html !!}</article>
        </div>
    </div>
</x-app-layout>
