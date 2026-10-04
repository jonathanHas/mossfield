{{-- Sub-navigation shared by every finance screen, so the queues stay one
     click apart no matter where you are. --}}
@php
    $tabs = [
        ['label' => 'Invoices', 'route' => 'invoicing.invoices.index', 'match' => 'invoicing.invoices.*'],
        ['label' => 'To invoice', 'route' => 'invoicing.uninvoiced.index', 'match' => 'invoicing.uninvoiced.*'],
        ['label' => 'Consolidated', 'route' => 'invoicing.consolidated.create', 'match' => 'invoicing.consolidated.*'],
        ['label' => 'Payments', 'route' => 'invoicing.payments.index', 'match' => 'invoicing.payments.*'],
        ['label' => 'Overdue', 'route' => 'invoicing.overdue.index', 'match' => 'invoicing.overdue.*'],
        ['label' => 'Statements', 'route' => 'invoicing.statements.index', 'match' => 'invoicing.statements.*'],
        ['label' => 'Sent', 'route' => 'invoicing.emails.index', 'match' => 'invoicing.emails.*'],
        ['label' => 'Export', 'route' => 'invoicing.export.index', 'match' => 'invoicing.export.*'],
    ];
@endphp
<div class="flex flex-wrap gap-1.5 mb-4">
    @foreach($tabs as $tab)
        @php $active = request()->routeIs($tab['match']); @endphp
        <a href="{{ route($tab['route']) }}"
           class="text-[12.5px] px-3 py-1.5 rounded-md {{ $active ? 'font-semibold' : '' }}"
           style="{{ $active
                ? 'background: var(--ink); color: var(--bg);'
                : 'background: var(--surface-2, #f4f5f2); color: var(--muted); border: 1px solid var(--line-2);' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
