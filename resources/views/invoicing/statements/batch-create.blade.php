<x-app-layout>
    <x-slot name="header">Month-end statements</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Month-end statement run</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                Generates a statement for each selected customer and queues the emails.
                Sending happens in the background — the run page shows progress and lets you retry failures.
            </div>
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="from" class="mf-label">From</label>
                    <input type="date" id="from" name="from" class="mf-input" value="{{ $from->toDateString() }}">
                </div>
                <div>
                    <label for="to" class="mf-label">To</label>
                    <input type="date" id="to" name="to" class="mf-input" value="{{ $to->toDateString() }}">
                </div>
                <div>
                    <label for="mode" class="mf-label">Send to</label>
                    <select name="mode" id="mode" class="mf-select">
                        <option value="balance" @selected($mode === 'balance')>Customers with a balance</option>
                        <option value="activity" @selected($mode === 'activity')>Customers with any activity</option>
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Update list</button>
            </form>
        </div>

        @if($missingEmail->isNotEmpty())
            <div class="mf-flash mf-flash-warn">
                <div>
                    <b>No billing address on file for {{ $missingEmail->count() }} {{ \Illuminate\Support\Str::plural('customer', $missingEmail->count()) }}:</b>
                    {{ $missingEmail->pluck('name')->implode(', ') }}. They can't be included.
                </div>
            </div>
        @endif

        @if($candidates->isEmpty())
            <div class="mf-panel px-4 py-6 text-[13px]" style="color: var(--muted);">
                No customers match for {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}.
            </div>
        @else
            <form method="POST" action="{{ route('invoicing.statements.batch.store') }}"
                  data-confirm="Generate and queue statements for the selected customers?" data-confirm-label="Generate">
                @csrf
                <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $to->toDateString() }}">

                <div class="mf-panel mb-4">
                    <div class="mf-panel-header">
                        <div class="flex-1">
                            <div class="text-[13px] font-semibold">
                                Recipients <span style="color: var(--muted); font-weight: 400;">({{ $candidates->count() }})</span>
                            </div>
                        </div>
                        <button type="submit" class="mf-btn-primary">Generate &amp; queue</button>
                    </div>
                    <table class="w-full">
                        <thead>
                            <tr>
                                <th class="mf-th" style="width: 40px;"></th>
                                <th class="mf-th">Customer</th>
                                <th class="mf-th">Send to</th>
                                <th class="mf-th text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($candidates as $customer)
                                <tr class="mf-tr-divide">
                                    <td class="mf-td">
                                        <input type="checkbox" name="customer_ids[]" value="{{ $customer->id }}" class="mf-checkbox" checked>
                                    </td>
                                    <td class="mf-td">
                                        <a href="{{ route('invoicing.statements.preview', ['customer' => $customer, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="mf-link">
                                            {{ $customer->name }}
                                        </a>
                                    </td>
                                    <td class="mf-td text-[12.5px]">{{ $customer->billingEmail() }}</td>
                                    <td class="mf-td text-right font-mono">€{{ number_format($customer->outstanding_balance, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @endif

        @if($recentBatches->isNotEmpty())
            <div class="mf-panel">
                <div class="mf-panel-header"><div class="text-[13px] font-semibold">Recent runs</div></div>
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Run</th>
                            <th class="mf-th">Statements</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentBatches as $batch)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">{{ \Illuminate\Support\Carbon::parse($batch->sent_at)->format('d/m/Y H:i') }}</td>
                                <td class="mf-td">{{ $batch->total }}</td>
                                <td class="mf-td text-right">
                                    <a href="{{ route('invoicing.statements.batch.show', $batch->batch_id) }}" class="mf-link text-[12.5px]">View run</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
