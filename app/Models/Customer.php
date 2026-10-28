<?php

namespace App\Models;

use App\Casts\EncryptedNullable;
use App\Enums\InvoiceDelivery;
use App\Enums\InvoicingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'email',
        'billing_email',
        'vat_number',
        'invoice_delivery',
        'invoicing_mode',
        'send_statements',
        'phone',
        'address',
        'city',
        'postal_code',
        'country',
        'credit_limit',
        'payment_terms',
        'is_active',
        'requires_reference',
        'notes',
        'mossorders_user_id',
        'delivery_run_id',
        'run_position',
        'apply_delivery_charge',
        'delivery_charge_percent',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'send_statements' => 'boolean',
        'invoice_delivery' => InvoiceDelivery::class,
        'invoicing_mode' => InvoicingMode::class,
        'requires_reference' => 'boolean',
        'mossorders_user_id' => 'integer',
        'run_position' => 'integer',
        'apply_delivery_charge' => 'boolean',
        'delivery_charge_percent' => 'decimal:2',
        // PII encrypted at rest. Name/email/country stay plaintext because
        // they are queried directly (unique checks, filtering).
        'phone' => EncryptedNullable::class,
        'address' => EncryptedNullable::class,
        'city' => EncryptedNullable::class,
        'postal_code' => EncryptedNullable::class,
        'notes' => EncryptedNullable::class,
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(CustomerSpecialPrice::class);
    }

    /**
     * Unit price this customer pays for a variant: the negotiated special
     * price if one exists, otherwise the variant's standard base_price. The
     * value is in the same units as base_price (€/unit, or €/kg when the
     * variant is priced by weight), so it drops straight into unit_price.
     */
    public function unitPriceFor(ProductVariant $variant): float
    {
        $special = $this->specialPrices()
            ->where('product_variant_id', $variant->id)
            ->value('price');

        return $special !== null ? (float) $special : (float) $variant->base_price;
    }

    /**
     * The delivery charge (€, ex VAT) a NEW order for this customer should
     * snapshot: the current run's charge when the customer is flagged, on a
     * run, and the run has a charge set — otherwise 0. Snapshot semantics:
     * changing the run's charge later never touches existing orders. This is
     * the single leverage point applied at every order-creation site.
     */
    public function currentDeliveryCharge(?CarbonInterface $deliveryDate = null, ?int $exceptOrderId = null): float
    {
        // A negotiated percentage overrides the fixed run charge; its € amount
        // is computed in Order::calculateTotals() (it needs the order subtotal).
        if ($this->deliveryChargePercent() !== null) {
            return 0.0;
        }

        if (! $this->apply_delivery_charge) {
            return 0.0;
        }

        // Once per drop (OPEN_QUESTIONS #5, F-24): a second order for the same
        // delivery day rides on the van stop the first one already paid for.
        if ($deliveryDate !== null && $this->deliveryChargeCarrierFor($deliveryDate, $exceptOrderId) !== null) {
            return 0.0;
        }

        return round(max(0, (float) ($this->deliveryRun?->delivery_charge ?? 0)), 2);
    }

    /**
     * The order that already carries the delivery charge for this customer's
     * drop on $deliveryDate (non-cancelled, charge > 0), or null. Snapshot
     * semantics: cancelling it later does not move the charge to another
     * order — the office edits the charge on the surviving one.
     */
    public function deliveryChargeCarrierFor(CarbonInterface $deliveryDate, ?int $exceptOrderId = null): ?Order
    {
        return $this->orders()
            ->whereDate('delivery_date', $deliveryDate->toDateString())
            ->where('status', '!=', 'cancelled')
            ->where('delivery_charge', '>', 0)
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->orderBy('id')
            ->first();
    }

    /**
     * The customer's negotiated delivery-charge rate (% of order value), or
     * null when they have none. Snapshotted onto new orders; when present it
     * overrides the fixed run charge (see [[currentDeliveryCharge]]).
     */
    public function deliveryChargePercent(): ?float
    {
        $pct = (float) ($this->delivery_charge_percent ?? 0);

        return $pct > 0 ? round($pct, 2) : null;
    }

    public function getFullAddressAttribute(): string
    {
        // City and postal code are optional — skip blanks rather than print ", ,".
        return collect([$this->address, $this->city, $this->postal_code, $this->country])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class);
    }

    /**
     * Where invoices, credit notes, statements and reminders are sent: the
     * dedicated billing address if the customer has one, else their main email.
     */
    public function billingEmail(): ?string
    {
        $email = trim((string) ($this->billing_email ?: $this->email));

        return $email !== '' ? $email : null;
    }

    public function isConsolidatedBilling(): bool
    {
        return ($this->invoicing_mode ?? InvoicingMode::PerOrder)->isConsolidated();
    }

    public function wantsEmailedInvoices(): bool
    {
        return ($this->invoice_delivery ?? InvoiceDelivery::Email) === InvoiceDelivery::Email;
    }

    /** Money received but not yet applied to any invoice — credit on account. */
    public function unallocatedCredit(): float
    {
        $paid = (float) $this->payments()->sum('amount');
        $allocated = (float) PaymentAllocation::whereIn(
            'payment_id', $this->payments()->select('id')
        )->sum('amount');

        return round(max(0, $paid - $allocated), 2);
    }

    /**
     * What the customer actually owes: the balance of every open (issued,
     * unsettled) invoice, less any credit sitting on account.
     *
     * Superseded the old "sum every order whose payment_status isn't paid",
     * which counted cancelled and un-delivered orders and billed a part-paid
     * order at its full value.
     */
    public function getOutstandingBalanceAttribute(): float
    {
        $open = (float) $this->invoices()->open()->sum('balance');

        return round($open - $this->unallocatedCredit(), 2);
    }

    /** Open invoice balances bucketed by how far past due they are. */
    public function agedBalances(): array
    {
        return app(\App\Services\StatementService::class)->agedBalances($this);
    }

    /** Same arithmetic as creditWarning(): a credit limit of 0 means no limit. */
    public function canPlaceOrder(float $orderAmount): bool
    {
        return $this->is_active && $this->creditWarning($orderAmount) === null;
    }

    /**
     * The credit limit WARNS, never blocks (OPEN_QUESTIONS #5, F-25): the
     * sentence to show when this order would take the customer over their
     * limit, or null when it wouldn't. A credit_limit of 0 means "no limit"
     * (it's the default, so every customer has it until the office sets one).
     */
    public function creditWarning(float $orderTotal): ?string
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) {
            return null;
        }

        $outstanding = (float) $this->outstanding_balance;
        $after = $outstanding + $orderTotal;
        if ($after <= $limit + 0.0001) {
            return null;
        }

        $eur = fn (float $v) => '€'.number_format($v, 2);

        return "{$this->name} is at {$eur($outstanding)} outstanding against a {$eur($limit)} credit limit — this order takes it to {$eur($after)}.";
    }

    public function hasOnlineAccount(): bool
    {
        return ! is_null($this->mossorders_user_id);
    }
}
