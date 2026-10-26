<?php

namespace App\Site\Contracts;

use App\Site\Data\Stockist;
use Illuminate\Support\Collection;

/**
 * Where the public website's stockist list comes from. Today a JSON file
 * (JsonStockistSource); later the customers table with a "show on website"
 * flag. Implementations return only the stockists that should be shown,
 * in any order: SiteBuilder sorts and groups them.
 */
interface StockistSource
{
    /** @return Collection<int, Stockist> */
    public function all(): Collection;
}
