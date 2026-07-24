<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed weekly chilled delivery run (e.g. "Tuesday · Dublin · Stuart").
 * Customers are assigned via customers.delivery_run_id + run_position; the
 * chilled run sheet pairs each stop with the customer's order for the run's
 * resolved date (see dateFor()).
 */
class DeliveryRun extends Model
{
    protected $fillable = [
        'name',
        'day_of_week',
        'driver',
        'capacity_note',
        'delivery_charge',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'delivery_charge' => 'decimal:2',
    ];

    public function customers(): HasMany
    {
        // Position order, unpositioned stops last; tie-break by name
        // (plaintext — never order by the encrypted PII columns).
        return $this->hasMany(Customer::class)
            ->orderByRaw('run_position is null')
            ->orderBy('run_position')
            ->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The concrete date this run's sheet represents within the ISO week
     * containing $weekAnchor (defaults to today). Whole-week runs
     * (day_of_week = null, e.g. a wholesaler collecting "w/c" orders)
     * resolve to the start of the week.
     */
    public function dateFor(?CarbonInterface $weekAnchor = null): CarbonImmutable
    {
        $weekStart = CarbonImmutable::parse($weekAnchor ?? now())
            ->startOfDay()
            ->startOfWeek(CarbonInterface::MONDAY);

        if ($this->day_of_week === null) {
            return $weekStart;
        }

        return $weekStart->addDays($this->day_of_week - 1);
    }

    /**
     * "Tuesday" for day-based runs; "Week of" for whole-week runs.
     */
    public function getDayLabelAttribute(): string
    {
        if ($this->day_of_week === null) {
            return 'Week of';
        }

        return CarbonImmutable::now()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addDays($this->day_of_week - 1)
            ->format('l');
    }
}
