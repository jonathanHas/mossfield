<?php

namespace App\Models;

use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Mail\DocumentMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * A customer statement for a period, stored as a JSON ledger snapshot rather
 * than a PDF blob. Payments can later be corrected or deleted, so re-deriving
 * the ledger from live data would not reliably reproduce what was emailed; the
 * PDF regenerates from this snapshot instead.
 */
class Statement extends Model implements EmailableDocument
{
    protected $fillable = [
        'customer_id', 'period_start', 'period_end',
        'opening_balance', 'closing_balance', 'aged', 'lines',
        'emailed_at', 'generated_at', 'generated_by', 'batch_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'aged' => 'array',
        'lines' => 'array',
        'emailed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function emails(): MorphMany
    {
        return $this->morphMany(DocumentEmail::class, 'documentable')->latest('id');
    }

    public function getPeriodLabelAttribute(): string
    {
        return $this->period_start->format('j M Y').' – '.$this->period_end->format('j M Y');
    }

    // --------------------------------------------------- EmailableDocument

    public function documentKind(): DocumentEmailKind
    {
        return DocumentEmailKind::Statement;
    }

    public function documentCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function documentSubject(): string
    {
        return config('invoicing.seller.name').' — Statement to '.$this->period_end->format('j M Y');
    }

    public function documentFilename(): string
    {
        return 'statement-'.$this->period_end->format('Y-m-d').'.pdf';
    }

    public function documentPdfData(): string
    {
        return app(\App\Services\StatementService::class)->pdf($this)->output();
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
