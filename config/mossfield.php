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

    /*
    |--------------------------------------------------------------------------
    | Batch quantity sanity ceilings
    |--------------------------------------------------------------------------
    |
    | Units per variant per batch above which the batch form asks "yes, this
    | batch really is that big" before saving (OPEN_QUESTIONS #5, F-09: a typo
    | of 3 000 wheels was accepted). A guard against typos, not a limit —
    | ticking the box saves it. Starting numbers are guesses from the dev data
    | (largest seen: milk 240, yoghurt 120, cheese 38 wheels); Jon sets them.
    |
    */
    'batch_ceilings' => [
        'milk' => 5000,
        'yoghurt' => 3000,
        'cheese' => 200,
    ],
];
