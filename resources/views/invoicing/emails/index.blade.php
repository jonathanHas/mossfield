<x-app-layout>
    <x-slot name="header">Sent documents</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Sent documents</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                Every invoice, credit note, statement, reminder and docket emailed from here — including the ones that failed.
            </div>
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="kind" class="mf-label">Document</label>
                    <select name="kind" id="kind" class="mf-select">
                        <option value="">All types</option>
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}" @selected(request('kind') === $kind->value)>{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="mf-label">Status</label>
                    <select name="status" id="status" class="mf-select">
                        <option value="">Any</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
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
                <a href="{{ route('invoicing.emails.index') }}" class="mf-link text-[12.5px]">Clear</a>
            </form>
        </div>

        <div class="mf-panel">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">When</th>
                        <th class="mf-th">Document</th>
                        <th class="mf-th">Customer</th>
                        <th class="mf-th">Sent to</th>
                        <th class="mf-th">By</th>
                        <th class="mf-th">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($emails as $email)
                        <tr class="mf-tr-divide">
                            <td class="mf-td">{{ ($email->sent_at ?? $email->failed_at ?? $email->queued_at)?->format('d/m/Y H:i') }}</td>
                            <td class="mf-td">
                                {{ $email->kind->label() }}
                                <div class="text-[11.5px]" style="color: var(--muted);">{{ $email->subject }}</div>
                            </td>
                            <td class="mf-td">{{ $email->customer?->name ?? '—' }}</td>
                            <td class="mf-td text-[12.5px]">{{ $email->recipient }}</td>
                            <td class="mf-td text-[12.5px]">{{ $email->sender?->name ?? 'Scheduled' }}</td>
                            <td class="mf-td">
                                <span class="mf-tag mf-tag-{{ $email->status_tone }}">{{ $email->status->label() }}</span>
                                @if($email->error)
                                    <div class="text-[11px] mt-0.5" style="color: var(--danger);">{{ $email->error }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mf-td text-center py-6" style="color: var(--muted);">Nothing has been emailed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $emails->links() }}</div>
    </div>
</x-app-layout>
