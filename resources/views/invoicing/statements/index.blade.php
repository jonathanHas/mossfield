<x-app-layout>
    <x-slot name="header">Statements</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Statements</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Every statement generated, and whether it was sent.</div>
            </div>
            <a href="{{ route('invoicing.statements.batch.create') }}" class="mf-btn-primary">Month-end run</a>
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="customer_id" class="mf-label">Customer</label>
                    <select name="customer_id" id="customer_id" class="mf-select">
                        <option value="">All customers</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>{{ $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                <a href="{{ route('invoicing.statements.index') }}" class="mf-link text-[12.5px]">Clear</a>
            </form>
        </div>

        <div class="mf-panel">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">Customer</th>
                        <th class="mf-th">Period</th>
                        <th class="mf-th">Generated</th>
                        <th class="mf-th text-right">Closing balance</th>
                        <th class="mf-th">Emailed</th>
                        <th class="mf-th"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($statements as $statement)
                        <tr class="mf-tr-divide mf-landing" id="statement-{{ $statement->id }}">
                            <td class="mf-td">
                                <a href="{{ route('invoicing.statements.preview', $statement->customer) }}" class="mf-link">
                                    {{ $statement->customer?->name }}
                                </a>
                            </td>
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
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mf-td text-center py-6" style="color: var(--muted);">No statements generated yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $statements->links() }}</div>
    </div>
</x-app-layout>
