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
        'total_weight_kg',
        'notes',
    ];

    protected $casts = [
        'cut_date' => 'date',
        'vacuum_packs_created' => 'integer',
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

    public function getAveragePackWeightAttribute(): float
    {
        return $this->vacuum_packs_created > 0 
            ? round((float)$this->total_weight_kg / $this->vacuum_packs_created, 3)
            : 0;
    }
}
