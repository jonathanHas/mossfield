<x-app-layout>
    <x-slot name="header">Statement · {{ $customer->name }}</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                Statement · <a href="{{ route('customers.show', $customer) }}" class="mf-link">{{ $customer->name }}</a>
            </h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}.
                Generating saves a snapshot so a re-print always matches what was sent.
            </div>
        </div>


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
                <button type="submit" class="mf-btn-secondary">Update</button>
            </form>
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="flex-1"><div class="text-[13px] font-semibold">Account activity</div></div>
                <form method="POST" action="{{ route('invoicing.statements.store', $customer) }}" class="flex gap-2">
                    @csrf
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                    <button type="submit" class="mf-btn-secondary">Generate</button>
                    @if($customer->billingEmail())
                        <button type="submit" name="send" value="1" class="mf-btn-primary"
                                data-confirm="Generate and email this statement to {{ $customer->billingEmail() }}?" data-confirm-label="Send">
                            Generate &amp; email
                        </button>
                    @endif
                </form>
            </div>
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">Date</th>
                        <th class="mf-th">Reference</th>
                        <th class="mf-th">Description</th>
                        <th class="mf-th text-right">Charges</th>
                        <th class="mf-th text-right">Payments</th>
                        <th class="mf-th text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="mf-tr-divide">
                        <td class="mf-td" colspan="5" style="color: var(--muted);">Balance brought forward</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($ledger['opening_balance'], 2) }}</td>
                    </tr>
                    @forelse($ledger['lines'] as $line)
                        <tr class="mf-tr-divide">
                            <td class="mf-td">{{ \Illuminate\Support\Carbon::parse($line['date'])->format('d/m/Y') }}</td>
                            <td class="mf-td font-mono text-[12px]">{{ $line['reference'] }}</td>
                            <td class="mf-td">{{ $line['description'] }}</td>
                            <td class="mf-td text-right font-mono">{{ $line['debit'] > 0 ? '€'.number_format($line['debit'], 2) : '' }}</td>
                            <td class="mf-td text-right font-mono">{{ $line['credit'] > 0 ? '€'.number_format($line['credit'], 2) : '' }}</td>
                            <td class="mf-td text-right font-mono">€{{ number_format($line['balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mf-td text-center py-6" style="color: var(--muted);">No activity in this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="mf-td text-right font-semibold">Balance due</td>
                        <td class="mf-td text-right font-mono font-semibold">€{{ number_format($ledger['closing_balance'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @php $aged = $ledger['aged']; @endphp
        <div class="mf-panel mb-4">
            <div class="mf-panel-header"><div class="text-[13px] font-semibold">Aged breakdown</div></div>
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th text-right">Not yet due</th>
                        <th class="mf-th text-right">1–30 days</th>
                        <th class="mf-th text-right">31–60 days</th>
                        <th class="mf-th text-right">61–90 days</th>
                        <th class="mf-th text-right">90+ days</th>
                        <th class="mf-th text-right">Credit</th>
                        <th class="mf-th text-right">Net owed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['current'], 2) }}</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['days_1_30'], 2) }}</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['days_31_60'], 2) }}</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['days_61_90'], 2) }}</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['days_90_plus'], 2) }}</td>
                        <td class="mf-td text-right font-mono">€{{ number_format($aged['credit'], 2) }}</td>
                        <td class="mf-td text-right font-mono font-semibold">€{{ number_format($aged['total'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if($history->isNotEmpty())
            <div class="mf-panel">
                <div class="mf-panel-header"><div class="text-[13px] font-semibold">Statements sent</div></div>
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Period</th>
                            <th class="mf-th">Generated</th>
                            <th class="mf-th text-right">Closing balance</th>
                            <th class="mf-th">Emailed</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $statement)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">{{ $statement->period_label }}</td>
                                <td class="mf-td">{{ $statement->generated_at?->format('d/m/Y') }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $statement->closing_balance, 2) }}</td>
                                <td class="mf-td">
                                    @if($statement->emailed_at)
                                        <span class="text-[12px]" style="color: var(--muted);">{{ $statement->emailed_at->format('d/m/Y') }}</span>
                                    @else
                                        <span class="mf-tag mf-tag-warn">Not sent</span>
                                    @endif
                                </td>
                                <td class="mf-td text-right">
                                    <a href="{{ route('invoicing.statements.pdf', $statement) }}" target="_blank" class="mf-link text-[12.5px]">PDF</a>
                                    @if($customer->billingEmail())
                                        <form method="POST" action="{{ route('invoicing.statements.email', $statement) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="mf-link text-[12.5px] ml-2">Email</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
