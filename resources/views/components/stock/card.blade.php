@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'mf-panel']) }}>
    @if($title || $subtitle || isset($tag))
        <div class="flex flex-wrap items-start gap-3 px-[18px] py-[14px]" style="border-bottom: 1px solid var(--line-2);">
            <div class="flex-1 min-w-0">
                @if($title)
                    <div class="text-[13.5px] font-semibold" style="color: var(--ink);">{{ $title }}</div>
                @endif
                @if($subtitle)
                    <div class="text-[12px] mt-0.5" style="color: var(--muted);">{{ $subtitle }}</div>
                @endif
            </div>
            @isset($tag)
                <div class="flex-shrink-0">{{ $tag }}</div>
            @endisset
        </div>
    @endif
    <div class="px-5 py-5">
        {{ $slot }}
    </div>
</div>
