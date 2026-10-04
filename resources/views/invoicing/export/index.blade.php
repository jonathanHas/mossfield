<x-app-layout>
    <x-slot name="header">Export</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Accounting export</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                CSV extracts for the accountant. Credit notes appear on the invoice sheet as negative
                amounts, so a column total is the period's net turnover.
            </div>
        </div>

        @include('invoicing.partials._nav')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach([
                ['type' => 'invoices', 'title' => 'Invoices & credit notes', 'blurb' => 'Date, number, customer, VAT number, net, VAT, gross, paid, credited, balance.'],
                ['type' => 'payments', 'title' => 'Payments', 'blurb' => 'Date, customer, amount, method, reference, what it settled, and any credit left on account.'],
            ] as $sheet)
                <div class="mf-panel">
                    <div class="mf-panel-header"><div class="text-[13px] font-semibold">{{ $sheet['title'] }}</div></div>
                    <form method="GET" action="{{ route('invoicing.export.download') }}" class="px-4 py-3">
                        <input type="hidden" name="type" value="{{ $sheet['type'] }}">
                        <p class="text-[12.5px] mb-3" style="color: var(--muted);">{{ $sheet['blurb'] }}</p>
                        <div class="flex flex-wrap gap-3 items-end">
                            <div>
                                <label class="mf-label" for="from_{{ $sheet['type'] }}">From</label>
                                <input type="date" id="from_{{ $sheet['type'] }}" name="from" class="mf-input" value="{{ $from->toDateString() }}" required>
                            </div>
                            <div>
                                <label class="mf-label" for="to_{{ $sheet['type'] }}">To</label>
                                <input type="date" id="to_{{ $sheet['type'] }}" name="to" class="mf-input" value="{{ $to->toDateString() }}" required>
                            </div>
                            <button type="submit" class="mf-btn-primary">Download CSV</button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
