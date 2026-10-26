<?php

namespace App\Site\Sources;

use App\Site\Contracts\StockistSource;
use App\Site\Data\Stockist;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Stockists from resources/site/data/stockists.json. Every entry is checked
 * so a typo fails the build with the offending index rather than quietly
 * dropping a shop. `show` defaults to true and mirrors the future
 * customers.show_on_website flag.
 */
final class JsonStockistSource implements StockistSource
{
    public function __construct(private readonly string $path) {}

    public function all(): Collection
    {
        $entries = JsonFile::read($this->path, 'stockists');

        return collect($entries)
            ->filter(fn (array $entry) => (bool) ($entry['show'] ?? true))
            ->map(fn (array $entry, int $i) => $this->make($entry, $i))
            ->values();
    }

    private function make(array $entry, int $i): Stockist
    {
        $where = "stockists.json[{$i}]";

        foreach (['name', 'county'] as $key) {
            if (! is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                throw new InvalidArgumentException("{$where}: missing '{$key}'");
            }
        }

        $products = $entry['products'] ?? null;
        if (! is_array($products)) {
            throw new InvalidArgumentException("{$where}: 'products' must be a list");
        }
        foreach ($products as $product) {
            if (! in_array($product, Stockist::PRODUCTS, true)) {
                throw new InvalidArgumentException("{$where}: unknown product '{$product}'");
            }
        }

        return new Stockist(
            name: trim($entry['name']),
            county: trim($entry['county']),
            address: JsonFile::optionalString($entry, 'address', $where),
            phone: JsonFile::optionalString($entry, 'phone', $where),
            products: array_values($products),
            website: JsonFile::optionalString($entry, 'website', $where),
        );
    }
}
