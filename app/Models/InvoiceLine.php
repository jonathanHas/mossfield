<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen line of an invoice. line_type separates the product lines from the
 * per-order delivery line (the only VAT-bearing row) and any manual adjustment.
 */
class InvoiceLine extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'invoice_id', 'order_id', 'order_item_id', 'line_type',
        'description', 'detail', 'batch_codes',
        'quantity', 'unit_price', 'unit_price_label', 'amount',
        'vat_rate', 'vat_amount', 'sort',
    ];

    protected $casts = [
        'batch_codes' => 'array',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isDelivery(): bool
    {
        return $this->line_type === self::TYPE_DELIVERY;
    }

    /**
     * Quantity as it should read on the document — whole units for products,
     * blank for the delivery line.
     */
    public function getQuantityLabelAttribute(): string
    {
        if ($this->isDelivery()) {
            return '';
        }

        $qty = (float) $this->quantity;

        return $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 3), '0'), '.');
    }
}
