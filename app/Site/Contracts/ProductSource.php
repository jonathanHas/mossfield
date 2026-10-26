<?php

namespace App\Site\Contracts;

use App\Site\Data\SiteProduct;
use Illuminate\Support\Collection;

/**
 * Where the public website's product list comes from. Today a JSON file
 * (JsonProductSource); later the products table with a "show on website"
 * flag. Implementations return only the products that should be shown;
 * SiteBuilder orders them by `sort`.
 */
interface ProductSource
{
    /** @return Collection<int, SiteProduct> */
    public function all(): Collection;
}
