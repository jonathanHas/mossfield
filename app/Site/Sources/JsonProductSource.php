<?php

namespace App\Site\Sources;

use App\Site\Contracts\ProductSource;
use App\Site\Data\SiteProduct;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Products from resources/site/data/products.json. `image` is a file name
 * inside the product-images directory; it is resolved to an absolute path
 * here so the builder (and the future Eloquent source, which hands over a
 * storage path) can treat images uniformly.
 */
final class JsonProductSource implements ProductSource
{
    public function __construct(
        private readonly string $path,
        private readonly string $imagesDir,
    ) {}

    public function all(): Collection
    {
        $entries = JsonFile::read($this->path, 'products');

        return collect($entries)
            ->filter(fn (array $entry) => (bool) ($entry['show'] ?? true))
            ->map(fn (array $entry, int $i) => $this->make($entry, $i))
            ->values();
    }

    private function make(array $entry, int $i): SiteProduct
    {
        $where = "products.json[{$i}]";

        foreach (['slug', 'name', 'type', 'description'] as $key) {
            if (! is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                throw new InvalidArgumentException("{$where}: missing '{$key}'");
            }
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry['slug'])) {
            throw new InvalidArgumentException("{$where}: slug '{$entry['slug']}' must be lowercase letters, digits and hyphens");
        }
        if (! in_array($entry['type'], SiteProduct::TYPES, true)) {
            throw new InvalidArgumentException("{$where}: unknown type '{$entry['type']}'");
        }

        $image = JsonFile::optionalString($entry, 'image', $where);
        $imageSource = null;
        if ($image !== null) {
            $imageSource = rtrim($this->imagesDir, '/').'/'.basename($image);
            if (! is_file($imageSource)) {
                throw new InvalidArgumentException("{$where}: image '{$image}' not found in {$this->imagesDir}");
            }
        }

        return new SiteProduct(
            slug: $entry['slug'],
            name: trim($entry['name']),
            type: $entry['type'],
            description: trim($entry['description']),
            imageSource: $imageSource,
            sort: (int) ($entry['sort'] ?? 0),
        );
    }
}
