<x-app-layout>
    <x-slot name="header">Statement run</x-slot>

    @php
        $sent = $rows->where('status', \App\Enums\DocumentEmailStatus::Sent)->count();
        $failed = $rows->where('status', \App\Enums\DocumentEmailStatus::Failed)->count();
        $queued = $rows->where('status', \App\Enums\DocumentEmailStatus::Queued)->count();
    @endphp

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Statement run</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('statement', $rows->count()) }} ·
                    {{ $sent }} sent · {{ $queued }} waiting · {{ $failed }} failed
                </div>
            </div>
            @if($failed > 0)
                <form method="POST" action="{{ route('invoicing.statements.batch.retry', $batch) }}">
                    @csrf
                    <button type="submit" class="mf-btn-primary">Retry {{ $failed }} failed</button>
                </form>
            @endif
        </div>


        @if($queued > 0)
            <div class="mf-flash mf-flash-warn">
                <div>
                    <b>{{ $queued }} still waiting to send.</b>
                    The scheduler drains the queue every minute, so refresh in a moment.
                    If they stay here, check that cron is running <span class="font-mono">schedule:run</span>.
                </div>
            </div>
        @endif

        <div class="mf-panel">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">Customer</th>
                        <th class="mf-th">Sent to</th>
                        <th class="mf-th">When</th>
                        <th class="mf-th">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="mf-tr-divide">
                            <td class="mf-td">{{ $row->customer?->name ?? '—' }}</td>
                            <td class="mf-td text-[12.5px]">{{ $row->recipient }}</td>
                            <td class="mf-td">{{ ($row->sent_at ?? $row->failed_at ?? $row->queued_at)?->format('d/m/Y H:i') }}</td>
                            <td class="mf-td">
                                <span class="mf-tag mf-tag-{{ $row->status_tone }}">{{ $row->status->label() }}</span>
                                @if($row->error)
                                    <div class="text-[11px] mt-0.5" style="color: var(--danger);">{{ $row->error }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
