@props(['href' => route('dashboard')])
{{-- The app's mark: the "M" square + "Mossfield Dairy". Used by the sidebar
     and the guest (login) layout so the two can't drift. --}}
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
    <div
        class="grid place-items-center"
        style="width: 28px; height: 28px; border-radius: 7px; background: var(--ink); color: var(--bg); font-family: 'Fraunces', serif; font-weight: 600; font-size: 17px;"
    >M</div>
    <div class="text-[14px] font-semibold" style="letter-spacing: -0.1px; color: var(--ink);">
        Mossfield<span class="font-normal" style="color: var(--muted);"> Dairy</span>
    </div>
</a>
