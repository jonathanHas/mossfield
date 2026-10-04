<?php

namespace App\Models;

use App\Enums\DocumentEmailKind;
use App\Enums\DocumentEmailStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per outbound document email — the audit trail behind "has this been
 * sent?". Written by App\Services\DocumentMailer for both the inline send path
 * and the queued batch path, so nothing leaves the building unlogged.
 */
class DocumentEmail extends Model
{
    protected $fillable = [
        'documentable_type', 'documentable_id', 'kind', 'customer_id',
        'recipient', 'subject', 'status', 'batch_id', 'sent_by',
        'queued_at', 'sent_at', 'failed_at', 'error',
    ];

    protected $casts = [
        'kind' => DocumentEmailKind::class,
        'status' => DocumentEmailStatus::class,
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', DocumentEmailStatus::Sent->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DocumentEmailStatus::Failed->value);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', DocumentEmailStatus::Queued->value);
    }

    public function getStatusToneAttribute(): string
    {
        return match ($this->status) {
            DocumentEmailStatus::Sent => 'accent',
            DocumentEmailStatus::Failed => 'danger',
            default => 'warn',
        };
    }
}
