{{-- Shared print/PDF styling for invoices, credit notes, statements and the
     order pro forma. DejaVu fonts are the ones dompdf ships with, so nothing
     falls back to a metric-incompatible face. --}}
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        color: #1a1a1a;
        font-size: 12px;
        line-height: 1.45;
        margin: 0;
        padding: 32px 36px;
    }
    .header {
        border-bottom: 2px solid #1a1a1a;
        padding-bottom: 12px;
        margin-bottom: 20px;
    }
    .header table { width: 100%; border-collapse: collapse; }
    .brand { font-size: 20px; font-weight: bold; letter-spacing: 0.3px; }
    .brand-sub { font-size: 11px; color: #666; margin-top: 2px; }
    .doc-title { font-size: 16px; font-weight: bold; text-align: right; }
    .doc-num { font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; text-align: right; color: #444; margin-top: 2px; }
    .doc-flag { text-align: right; font-size: 11px; font-weight: bold; color: #b3261e; margin-top: 3px; letter-spacing: 0.5px; }

    .meta { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
    .meta td { vertical-align: top; width: 50%; padding: 0; }
    .block-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: #888; margin-bottom: 4px; }
    .block-body { font-size: 12px; }
    .block-body strong { font-size: 13px; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.items th {
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #555;
        border-bottom: 1.5px solid #1a1a1a;
        padding: 6px 8px;
    }
    table.items td {
        padding: 7px 8px;
        border-bottom: 1px solid #ddd;
        font-size: 12px;
    }
    table.items .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
    table.items tr.group td {
        background: #f6f6f4;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #555;
        padding: 5px 8px;
        border-bottom: 1px solid #ddd;
    }
    .muted { color: #999; }
    .batch { font-size: 10px; color: #666; margin-top: 2px; font-family: 'DejaVu Sans Mono', monospace; }
    .short-note { font-size: 10px; color: #8a6d3b; margin-top: 2px; font-style: italic; }

    table.totals { margin-top: 14px; margin-left: auto; border-collapse: collapse; }
    table.totals td { padding: 4px 8px; font-size: 12px; }
    table.totals td.label { color: #666; text-align: right; }
    table.totals td.val { text-align: right; font-family: 'DejaVu Sans Mono', monospace; min-width: 100px; }
    table.totals tr.grand td { font-size: 14px; font-weight: bold; border-top: 1.5px solid #1a1a1a; padding-top: 8px; }
    table.totals tr.due td { font-size: 14px; font-weight: bold; border-top: 1px solid #1a1a1a; padding-top: 7px; }

    .notes { margin-top: 22px; padding: 10px 12px; background: #f6f6f4; border: 1px solid #e2e2dd; }
    .notes .block-label { margin-bottom: 3px; }

    .aged { width: 100%; border-collapse: collapse; margin-top: 18px; }
    .aged th, .aged td {
        border: 1px solid #ddd;
        padding: 6px 8px;
        font-size: 11px;
        text-align: right;
    }
    .aged th { background: #f6f6f4; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #555; }
    .aged td { font-family: 'DejaVu Sans Mono', monospace; }

    .pay-details { margin-top: 22px; padding: 10px 12px; border: 1px solid #e2e2dd; }
    .pay-details td { font-size: 11px; padding: 2px 10px 2px 0; }
    .pay-details .k { color: #888; }
    .pay-details .v { font-family: 'DejaVu Sans Mono', monospace; }

    .footer { margin-top: 34px; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }

    .void-stamp {
        position: fixed;
        top: 300px; left: 0; right: 0;
        text-align: center;
        font-size: 90px;
        font-weight: bold;
        color: #b3261e;
        opacity: 0.14;
        letter-spacing: 12px;
    }

    .toolbar {
        display: flex; gap: 8px; justify-content: flex-end;
        margin: -12px -12px 20px; padding: 10px 12px;
        background: #f6f6f4; border: 1px solid #e2e2dd; border-radius: 6px;
    }
    .toolbar a, .toolbar button {
        font: inherit; font-size: 12px; cursor: pointer;
        border: 1px solid #1a1a1a; border-radius: 5px;
        padding: 6px 14px; text-decoration: none; color: #1a1a1a; background: #fff;
    }
    .toolbar button.primary { background: #1a1a1a; color: #fff; }
    @media print { .toolbar { display: none !important; } }
</style>
