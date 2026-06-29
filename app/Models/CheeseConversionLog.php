<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheeseConversionLog extends Model
{
    protected $fillable = [
        'source_batch_item_id',
        'target_batch_item_id',
        'conversion_date',
        'wheels_converted',
        'total_weight_kg',
        'notes',
    ];

    protected $casts = [
        'conversion_date' => 'date',
        'wheels_converted' => 'integer',
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

    public function getAverageWheelWeightAttribute(): float
    {
        return $this->wheels_converted > 0
            ? round((float) $this->total_weight_kg / $this->wheels_converted, 3)
            : 0;
    }
}
