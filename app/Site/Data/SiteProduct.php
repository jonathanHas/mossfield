<?php

namespace App\Site\Data;

/**
 * One product on the public website. Named SiteProduct so it never collides
 * with App\Models\Product, which the future Eloquent source will map from.
 */
final readonly class SiteProduct
{
    public const TYPES = ['milk', 'yoghurt', 'cheese'];

    public function __construct(
        public string $slug,
        public string $name,
        public string $type,
        public string $description,
        public ?string $imageSource,
        public int $sort = 0,
    ) {}

    /** @return list<string> paragraphs, split on blank lines */
    public function paragraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', trim($this->description)))));
    }
}
