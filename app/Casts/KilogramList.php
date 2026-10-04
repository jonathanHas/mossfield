<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON list of kg figures that always comes back as a list<float> (JSON
 * would otherwise hand 4.000 back as int 4), rounded to the 3 dp the weight
 * columns use.
 *
 * @implements CastsAttributes<list<float>|null, iterable<int|float|string>|null>
 */
class KilogramList implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return array_values(array_map(fn ($w) => round((float) $w, 3), (array) $decoded));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(array_values(array_map(fn ($w) => round((float) $w, 3), (array) $value)));
    }
}
