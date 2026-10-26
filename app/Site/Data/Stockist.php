<?php

namespace App\Site\Data;

/**
 * One shop on the public "Where to buy" page. Plain public data by
 * definition, so never built from the encrypted customer address/phone.
 */
final readonly class Stockist
{
    public const PRODUCTS = ['milk', 'yoghurt', 'cheese'];

    /**
     * @param  list<string>  $products  subset of self::PRODUCTS
     */
    public function __construct(
        public string $name,
        public string $county,
        public ?string $address,
        public ?string $phone,
        public array $products,
        public ?string $website = null,
    ) {}
}
