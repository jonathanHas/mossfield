<?php

namespace App\Providers;

use App\Site\Contracts\ProductSource;
use App\Site\Contracts\StockistSource;
use App\Site\Sources\JsonProductSource;
use App\Site\Sources\JsonStockistSource;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the public-website data sources. Swapping the JSON files for the
 * customers/products tables later is a change to these two bindings only.
 */
class SiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockistSource::class, fn () => new JsonStockistSource(
            config('site.data_dir').'/stockists.json',
        ));

        $this->app->bind(ProductSource::class, fn () => new JsonProductSource(
            config('site.data_dir').'/products.json',
            config('site.product_images_dir'),
        ));
    }
}
