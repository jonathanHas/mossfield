<x-app-layout>
    <x-slot name="header">Consolidated invoices</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Consolidated invoice run</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                One invoice per customer covering every uninvoiced delivery in the period.
                Previewing costs nothing — no invoice number is used until you issue.
            </div>
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="start" class="mf-label">From</label>
                    <input type="date" id="start" name="start" class="mf-input" value="{{ $start->toDateString() }}">
                </div>
                <div>
                    <label for="end" class="mf-label">To</label>
                    <input type="date" id="end" name="end" class="mf-input" value="{{ $end->toDateString() }}">
                </div>
                <button type="submit" class="mf-btn-secondary">Preview period</button>
                <div class="flex gap-2 text-[12.5px]">
                    <a href="{{ route('invoicing.consolidated.create', ['period' => 'last_month']) }}" class="mf-link">Last month</a>
                    <a href="{{ route('invoicing.consolidated.create', ['period' => 'this_month']) }}" class="mf-link">This month</a>
                    <a href="{{ route('invoicing.consolidated.create', ['period' => 'last_week']) }}" class="mf-link">Last week</a>
                    <a href="{{ route('invoicing.consolidated.create', ['period' => 'this_week']) }}" class="mf-link">This week</a>
                </div>
            </form>
        </div>

        @if($customers->isEmpty())
            <div class="mf-panel px-4 py-6 text-[13px]" style="color: var(--muted);">
                No consolidated-billing customers have uninvoiced deliveries between
                {{ $start->format('j M Y') }} and {{ $end->format('j M Y') }}.
            </div>
        @else
            <form method="POST" action="{{ route('invoicing.consolidated.store') }}"
                  data-confirm="Issue consolidated invoices for the selected customers?" data-confirm-label="Issue">
                @csrf
                <input type="hidden" name="start" value="{{ $start->toDateString() }}">
                <input type="hidden" name="end" value="{{ $end->toDateString() }}">

                <div class="flex items-center justify-between mb-3">
                    <div class="text-[13px]" style="color: var(--muted);">
                        {{ $customers->count() }} {{ \Illuminate\Support\Str::plural('customer', $customers->count()) }}
                        · {{ $start->format('j M Y') }} – {{ $end->format('j M Y') }}
                    </div>
                    <button type="submit" class="mf-btn-primary">Issue selected</button>
                </div>

                @foreach($customers as $customer)
                    @php $preview = $previews[$customer->id]; @endphp
                    <div class="mf-panel mb-3">
                        <div class="mf-panel-header">
                            <label class="flex items-center gap-2 flex-1 cursor-pointer">
                                <input type="checkbox" name="customer_ids[]" value="{{ $customer->id }}" class="mf-checkbox" checked>
                                <span class="text-[13px] font-semibold">{{ $customer->name }}</span>
                                <span class="mf-tag mf-tag-info">{{ $customer->invoicing_mode->label() }}</span>
                            </label>
                            <div class="font-mono text-[13px] font-semibold">€{{ number_format((float) $preview->total, 2) }}</div>
                        </div>
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="mf-th">Description</th>
                                    <th class="mf-th text-right">Qty</th>
                                    <th class="mf-th text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview->lines as $line)
                                    <tr class="mf-tr-divide">
                                        <td class="mf-td">
                                            {{ $line->description }}
                                            @if(filled($line->detail))
                                                <div class="text-[11.5px]" style="color: var(--warn-ink, #8a6d3b);">{{ $line->detail }}</div>
                                            @endif
                                        </td>
                                        <td class="mf-td text-right font-mono">{{ $line->quantity_label ?: '—' }}</td>
                                        <td class="mf-td text-right font-mono">€{{ number_format((float) $line->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </form>
        @endif
    </div>
</x-app-layout>
