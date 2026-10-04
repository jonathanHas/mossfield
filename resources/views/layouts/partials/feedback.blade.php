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
    <div class="px-6 pt-5 flex flex-col gap-2" role="status" aria-live="polite">
        @foreach ($feedbackFlashes as $flash)
            <div class="mf-flash mf-flash-{{ $flash['tone'] }}" style="margin-bottom: 0;">{{ $flash['text'] }}</div>
        @endforeach
        @if ($feedbackErrors)
            <div class="mf-flash mf-flash-error" style="margin-bottom: 0;">
                <div>
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
@endif
