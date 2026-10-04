{{--
    The one place session flashes and the default validation bag are rendered
    for every x-app-layout page. Controllers redirect with ->with('success'|
    'error'|'warning') or ->withErrors(); nothing else is needed for the
    message to show, whichever page the redirect lands on. Pages must NOT
    render these themselves (it would double up) — per-field <x-input-error>
    is fine and unrelated.
--}}
@php
    $feedbackFlashes = collect(['success', 'error', 'warning'])
        ->filter(fn ($k) => filled(session($k)))
        ->map(fn ($k) => ['tone' => $k === 'warning' ? 'warn' : $k, 'text' => session($k)]);
    $feedbackErrors = $errors->getBag('default')->all();
@endphp
@if ($feedbackFlashes->isNotEmpty() || $feedbackErrors)
    {{-- Sticky (.mf-feedback): a redirect that lands on #line-… / #stop-…
         scrolls the page, and the message must still be in view. Each one
         can be dismissed. --}}
    <div class="mf-feedback px-6 pt-5 pb-2 flex flex-col gap-2" role="status" aria-live="polite"
         x-data="{ open: {{ $feedbackFlashes->count() + ($feedbackErrors ? 1 : 0) }} }" x-show="open > 0"
         x-effect="if (open === 0) document.documentElement.style.setProperty('--mf-feedback-h', '0px')">
        @foreach ($feedbackFlashes as $flash)
            <div class="mf-flash mf-flash-{{ $flash['tone'] }}" style="margin-bottom: 0;" x-data="{ show: true }" x-show="show">
                <div class="flex-1">{{ $flash['text'] }}</div>
                <button type="button" class="mf-feedback-close" @click="show = false; open--" aria-label="Dismiss message">&times;</button>
            </div>
        @endforeach
        @if ($feedbackErrors)
            <div class="mf-flash mf-flash-error" style="margin-bottom: 0;" x-data="{ show: true }" x-show="show">
                <button type="button" class="mf-feedback-close order-last" @click="show = false; open--" aria-label="Dismiss message">&times;</button>
                <div class="flex-1">
                    @if (count($feedbackErrors) > 1)
                        <strong>Please fix the following:</strong>
                        <ul class="mt-1 list-disc list-inside text-[12.5px]">
                            @foreach ($feedbackErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @else
                        {{ $feedbackErrors[0] }}
                    @endif
                </div>
            </div>
        @endif
    </div>
    {{-- Measure the region while the page is still parsing — before the
         browser scrolls to a #fragment — so sticky things below it (the run
         sheet's header) and scroll margins can sit under it. --}}
    <script>document.documentElement.style.setProperty('--mf-feedback-h', document.currentScript.previousElementSibling.offsetHeight + 'px');</script>
@endif
