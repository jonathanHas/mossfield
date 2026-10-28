<?php

namespace App\View;

/**
 * The one list of Finance screens (UX review 2026-10-13, E29): the sidebar's
 * Finance group and the finance tab bar both render it, so they can't drift
 * apart in order, labels or membership again.
 */
final class FinanceNav
{
    /**
     * @return list<array{id: string, label: string, route: string, match: string, icon: string}>
     */
    public static function items(): array
    {
        return [
            ['id' => 'invoices', 'label' => 'Invoices', 'route' => 'invoicing.invoices.index', 'match' => 'invoicing.invoices.*', 'icon' => 'invoice'],
            ['id' => 'tobill', 'label' => 'To invoice', 'route' => 'invoicing.uninvoiced.index', 'match' => 'invoicing.uninvoiced.*', 'icon' => 'euro'],
            ['id' => 'consolidated', 'label' => 'Consolidated', 'route' => 'invoicing.consolidated.create', 'match' => 'invoicing.consolidated.*', 'icon' => 'layers'],
            ['id' => 'payments', 'label' => 'Payments', 'route' => 'invoicing.payments.index', 'match' => 'invoicing.payments.*', 'icon' => 'euro'],
            ['id' => 'overdue', 'label' => 'Overdue', 'route' => 'invoicing.overdue.index', 'match' => 'invoicing.overdue.*', 'icon' => 'alert'],
            ['id' => 'statements', 'label' => 'Statements', 'route' => 'invoicing.statements.index', 'match' => 'invoicing.statements.*', 'icon' => 'statement'],
            ['id' => 'sent', 'label' => 'Sent', 'route' => 'invoicing.emails.index', 'match' => 'invoicing.emails.*', 'icon' => 'send'],
            ['id' => 'export', 'label' => 'Export', 'route' => 'invoicing.export.index', 'match' => 'invoicing.export.*', 'icon' => 'download'],
        ];
    }
}
