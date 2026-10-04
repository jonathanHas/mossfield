<?php

namespace App\Models;

use App\Casts\EncryptedNullable;
use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Mail\DocumentMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * An issued invoice — a frozen snapshot, not a view of the order.
 *
 * Order lines keep moving after issue (picks, weight edits, calculateTotals),
 * so every figure and every line here is copied at issue time. The link back is
 * orders.invoice_id (the order's LIVE invoice); invoice_lines.order_id keeps the
 * history when an invoice is voided and the order re-issued.
 *
 * Settlement state (amount_paid / amount_credited / balance) is CACHED so lists
 * can filter and sum in SQL; refreshBalances() is the only writer. Whether an
 * invoice is overdue is DERIVED from balance + due_date, so it is never stale.
 */
class Invoice extends Model implements EmailableDocument
{
    protected $fillable = [
        'number', 'customer_id', 'type', 'period_start', 'period_end', 'status',
        'issued_at', 'due_date', 'payment_terms',
        'bill_to_name', 'bill_to_address', 'bill_to_email', 'bill_to_vat_number',
        'subtotal', 'delivery_charge_net', 'tax_amount', 'total',
        'amount_paid', 'amount_credited', 'balance',
        'voided_at', 'void_reason', 'notes', 'emailed_at', 'last_reminded_at',
        'created_by',
    ];

    protected $casts = [
        'type' => InvoiceType::class,
        'status' => InvoiceStatus::class,
        'issued_at' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'voided_at' => 'datetime',
        'emailed_at' => 'datetime',
        'last_reminded_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'delivery_charge_net' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_credited' => 'decimal:2',
        'balance' => 'decimal:2',
        // Copied from the customer's already-encrypted address; keep it that way.
        'bill_to_address' => EncryptedNullable::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort')->orderBy('id');
    }

    /** Orders currently billed on this invoice (null once voided). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class, PaymentAllocation::class,
            'invoice_id', 'id', 'id', 'payment_id'
        );
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function emails(): MorphMany
    {
        return $this->morphMany(DocumentEmail::class, 'documentable')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Issued->value);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Void->value);
    }

    /** Issued and still owing money. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->issued()->where('balance', '>', 0);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', today());
    }

    /**
     * Issued, never successfully emailed, and actually meant to be emailed —
     * a "print" or "none" customer was never going to be, so counting them
     * would make the queue permanently non-empty.
     */
    public function scopeNotEmailed(Builder $query): Builder
    {
        return $query->issued()
            ->whereNull('emailed_at')
            ->whereHas('customer', fn ($q) => $q->where('invoice_delivery', 'email'));
    }

    // ------------------------------------------------------------- accessors

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    public function isIssued(): bool
    {
        return $this->status === InvoiceStatus::Issued;
    }

    public function isConsolidated(): bool
    {
        return $this->type === InvoiceType::Consolidated;
    }

    public function isOverdue(): bool
    {
        return $this->isIssued()
            && (float) $this->balance > 0
            && $this->due_date !== null
            && $this->due_date->isBefore(today());
    }

    /**
     * Derived settlement state — never stored, so it can't disagree with the
     * ledger: void | paid | overdue | partial | unpaid.
     */
    public function getPaymentStateAttribute(): string
    {
        if ($this->isVoid()) {
            return 'void';
        }

        if ((float) $this->balance <= 0) {
            return 'paid';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return ((float) $this->amount_paid > 0 || (float) $this->amount_credited > 0)
            ? 'partial'
            : 'unpaid';
    }

    public function getPaymentStateLabelAttribute(): string
    {
        return match ($this->payment_state) {
            'void' => 'Void',
            'paid' => 'Paid',
            'overdue' => 'Overdue',
            'partial' => 'Part paid',
            default => 'Unpaid',
        };
    }

    public function getPaymentStateToneAttribute(): string
    {
        return match ($this->payment_state) {
            'paid' => 'accent',
            'overdue' => 'danger',
            'partial' => 'info',
            'void' => 'neutral',
            default => 'warn',
        };
    }

    /** Whole days past the due date, 0 when not overdue. */
    public function getDaysOverdueAttribute(): int
    {
        return $this->isOverdue() ? (int) $this->due_date->diffInDays(today()) : 0;
    }

    public function hasBeenEmailed(): bool
    {
        return $this->emailed_at !== null;
    }

    // ------------------------------------------------------------ operations

    /**
     * Recompute the cached settlement figures from the allocation and credit-note
     * ledgers, then mirror the result onto the order(s). The single writer of
     * amount_paid / amount_credited / balance — never set them by hand.
     */
    public function refreshBalances(): void
    {
        $paid = (float) $this->allocations()->sum('amount');
        $credited = (float) $this->creditNotes()
            ->where('status', 'issued')
            ->sum('total');

        $this->amount_paid = round($paid, 2);
        $this->amount_credited = round($credited, 2);
        // A void invoice owes nothing — it never existed commercially.
        $this->balance = $this->isVoid()
            ? 0
            : round(max(0, (float) $this->total - $paid - $credited), 2);
        $this->save();

        $this->syncOrderPaymentStatus();
    }

    /**
     * Mirror the derived state onto orders.payment_status so the existing order
     * badges and index filters keep working. The column is a MIRROR: nothing
     * user-facing writes it any more.
     */
    public function syncOrderPaymentStatus(): void
    {
        $state = $this->payment_state;

        $mirrored = match ($state) {
            'paid' => 'paid',
            'partial' => 'partial',
            'overdue' => 'overdue',
            // A voided invoice returns its orders to "not yet billed".
            'void' => 'pending',
            default => 'pending',
        };

        Order::where('invoice_id', $this->id)->update(['payment_status' => $mirrored]);
    }

    /**
     * An invoice can only be voided while nothing is settled against it —
     * otherwise the payment or credit note would be left pointing at a document
     * that no longer exists. The UI tells the user to unallocate first.
     */
    public function isVoidable(): bool
    {
        return $this->isIssued()
            && ! $this->allocations()->exists()
            && ! $this->creditNotes()->where('status', 'issued')->exists();
    }

    /** Can a reminder legitimately be sent right now? */
    public function canBeReminded(): bool
    {
        return $this->isOverdue()
            && $this->days_overdue >= (int) config('invoicing.reminder.min_days_overdue', 1)
            && filled($this->documentRecipient());
    }

    // --------------------------------------------------- EmailableDocument

    public function documentKind(): DocumentEmailKind
    {
        return DocumentEmailKind::Invoice;
    }

    public function documentCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function documentSubject(): string
    {
        return config('invoicing.seller.name').' — Invoice '.$this->number;
    }

    public function documentFilename(): string
    {
        return 'invoice-'.$this->number.'.pdf';
    }

    public function documentPdfData(): string
    {
        return app(\App\Services\InvoiceService::class)->pdf($this)->output();
    }

    public function documentMailable(): Mailable
    {
        return new DocumentMail($this);
    }

    public function documentRecipient(): ?string
    {
        $email = trim((string) ($this->customer?->billingEmail() ?? $this->bill_to_email));

        return $email !== '' ? $email : null;
    }

    public function markEmailed(Carbon $at): void
    {
        $this->forceFill(['emailed_at' => $at])->save();
    }
}
