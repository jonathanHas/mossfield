<?php

namespace App\Models;

use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Mail\DocumentMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * A credit raised against an issued invoice (return, breakage, price
 * correction). It reduces invoices.amount_credited directly rather than being
 * allocated like a payment — a credit note is always tied to one invoice, so a
 * second allocation table would just be a second place to check.
 */
class CreditNote extends Model implements EmailableDocument
{
    protected $fillable = [
        'number', 'invoice_id', 'customer_id', 'status', 'reason', 'issued_at',
        'subtotal', 'tax_amount', 'total', 'voided_at', 'void_reason',
        'emailed_at', 'created_by',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'voided_at' => 'datetime',
        'emailed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('sort')->orderBy('id');
    }

    public function emails(): MorphMany
    {
        return $this->morphMany(DocumentEmail::class, 'documentable')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', 'issued');
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    // --------------------------------------------------- EmailableDocument

    public function documentKind(): DocumentEmailKind
    {
        return DocumentEmailKind::CreditNote;
    }

    public function documentCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function documentSubject(): string
    {
        return config('invoicing.seller.name').' — Credit note '.$this->number;
    }

    public function documentFilename(): string
    {
        return 'credit-note-'.$this->number.'.pdf';
    }

    public function documentPdfData(): string
    {
        return app(\App\Services\CreditNoteService::class)->pdf($this)->output();
    }

    public function documentMailable(): Mailable
    {
        return new DocumentMail($this);
    }

    public function documentRecipient(): ?string
    {
        $email = trim((string) $this->customer?->billingEmail());

        return $email !== '' ? $email : null;
    }

    public function markEmailed(Carbon $at): void
    {
        $this->forceFill(['emailed_at' => $at])->save();
    }
}
