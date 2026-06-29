<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mature cheese conversion threshold
    |--------------------------------------------------------------------------
    |
    | Age (in months from production_date) at which a Farmhouse cheese batch
    | becomes eligible to convert into Mature cheese. Used by
    | Batch::isEligibleForMaturation() and the cheese-conversion screens.
    | This is distinct from a product's maturation_days (ready-to-sell date).
    |
    */
    'mature_conversion_months' => (int) env('MATURE_CONVERSION_MONTHS', 5),
];
