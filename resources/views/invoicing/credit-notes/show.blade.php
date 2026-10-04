<x-app-layout>
    <x-slot name="header">Credit note {{ $creditNote->number }}</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium font-mono" style="letter-spacing: -0.4px;">{{ $creditNote->number }}</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    {{ $creditNote->customer?->name }} · against
                    <a href="{{ route('invoicing.invoices.show', $creditNote->invoice) }}" class="mf-link font-mono">{{ $creditNote->invoice?->number }}</a>
                    · {{ $creditNote->issued_at?->format('j M Y') }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('invoicing.credit-notes.pdf', $creditNote) }}" target="_blank" class="mf-btn-secondary">View PDF</a>
                <a href="{{ route('invoicing.credit-notes.pdf', ['credit_note' => $creditNote, 'download' => 1]) }}" class="mf-btn-secondary">Download</a>
                @if($creditNote->isIssued() && $creditNote->documentRecipient())
                    <form method="POST" action="{{ route('invoicing.credit-notes.email', $creditNote) }}"
                          onsubmit="return confirm('Email credit note {{ $creditNote->number }}?')">
                        @csrf
                        <button type="submit" class="mf-btn-primary">{{ $creditNote->emailed_at ? 'Email again' : 'Email' }}</button>
                    </form>
                @endif
                @can('void', $creditNote)
                    <button type="button" class="mf-btn-danger" x-data @click="$dispatch('open-modal', 'void-credit-note')">Void</button>
                @endcan
            </div>
        </div>


        @if($creditNote->isVoid())
            <div class="mf-flash mf-flash-error">
                <div><b>Voided {{ $creditNote->voided_at?->format('j M Y') }}.</b> {{ $creditNote->void_reason }}</div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
            <div class="mf-panel">
                <div class="mf-panel-header"><div class="text-[13px] font-semibold">Lines</div></div>
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Description</th>
                            <th class="mf-th text-right">Qty</th>
                            <th class="mf-th text-right">VAT</th>
                            <th class="mf-th text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($creditNote->lines as $line)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">{{ $line->description }}</td>
                                <td class="mf-td text-right font-mono">{{ (float) $line->quantity > 0 ? rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') : '—' }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $line->vat_amount, 2) }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $line->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                <div class="mf-panel mb-4">
                    <div class="mf-panel-header"><div class="text-[13px] font-semibold">Summary</div></div>
                    <dl class="px-4 py-3 text-[13px] grid grid-cols-[1fr_auto] gap-y-2">
                        <dt style="color: var(--muted);">Subtotal</dt>
                        <dd class="font-mono text-right">€{{ number_format((float) $creditNote->subtotal, 2) }}</dd>
                        <dt style="color: var(--muted);">VAT</dt>
                        <dd class="font-mono text-right">€{{ number_format((float) $creditNote->tax_amount, 2) }}</dd>
                        <dt class="font-semibold pt-2" style="border-top: 1px solid var(--line-2);">Total credited</dt>
                        <dd class="font-mono text-right font-semibold pt-2" style="border-top: 1px solid var(--line-2);">€{{ number_format((float) $creditNote->total, 2) }}</dd>
                        <dt style="color: var(--muted);">Reason</dt>
                        <dd class="text-right">{{ $creditNote->reason }}</dd>
                    </dl>
                </div>

                @if($creditNote->emails->isNotEmpty())
                    <div class="mf-panel">
                        <div class="mf-panel-header"><div class="text-[13px] font-semibold">Email history</div></div>
                        <div class="px-4 py-3 text-[12.5px]">
                            @foreach($creditNote->emails as $email)
                                <div class="flex justify-between gap-2 py-1">
                                    <span>{{ ($email->sent_at ?? $email->queued_at)?->format('d/m/Y H:i') }} · {{ $email->recipient }}</span>
                                    <span class="mf-tag mf-tag-{{ $email->status_tone }}">{{ $email->status->label() }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @can('void', $creditNote)
        <x-modal name="void-credit-note" focusable>
            <form method="POST" action="{{ route('invoicing.credit-notes.void', $creditNote) }}" class="p-6">
                @csrf
                <h2 class="text-[15px] font-semibold">Void credit note {{ $creditNote->number }}?</h2>
                <p class="mt-2 text-[13px]" style="color: var(--muted);">
                    The credit is withdrawn and invoice {{ $creditNote->invoice?->number }} goes back to owing that amount.
                </p>
                <div class="mt-4">
                    <label for="cn_void_reason" class="mf-label">Reason</label>
                    <input id="cn_void_reason" name="void_reason" type="text" class="mf-input" required maxlength="255">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="mf-btn-secondary" x-on:click="$dispatch('close')">Cancel</button>
                    <button type="submit" class="mf-btn-danger">Void credit note</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-layout>
