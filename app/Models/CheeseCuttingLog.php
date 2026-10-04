<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheeseCuttingLog extends Model
{
    protected $fillable = [
        'source_batch_item_id',
        'target_batch_item_id',
        'cut_date',
        'vacuum_packs_created',
        'wheel_weight_kg',
        'total_weight_kg',
        'notes',
    ];

    protected $casts = [
        'cut_date' => 'date',
        'vacuum_packs_created' => 'integer',
        'wheel_weight_kg' => 'decimal:3',
        'total_weight_kg' => 'decimal:3',
    ];

    public function sourceBatchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class, 'source_batch_item_id');
    }

    public function targetBatchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class, 'target_batch_item_id');
    }

    /** The wheel was weighed before cutting (rows from before 2026-09-13 were not). */
    public function isMeasured(): bool
    {
        return $this->wheel_weight_kg !== null;
    }

    /**
     * Trim/rind lost between the wheel and the packs that came off it:
     * wheel_weight_kg − total_weight_kg. Null when the wheel was never weighed
     * — never a nominal figure dressed as a measurement.
     */
    public function getWasteKgAttribute(): ?float
    {
        if (! $this->isMeasured()) {
            return null;
        }

        return round((float) $this->wheel_weight_kg - (float) $this->total_weight_kg, 3);
    }

    public function getAveragePackWeightAttribute(): float
    {
        return $this->vacuum_packs_created > 0
            ? round((float) $this->total_weight_kg / $this->vacuum_packs_created, 3)
            : 0;
    }
}
