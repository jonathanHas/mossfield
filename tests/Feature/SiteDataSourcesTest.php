<?php

namespace Tests\Feature;

use App\Site\Data\SiteProduct;
use App\Site\Data\Stockist;
use App\Site\Sources\JsonProductSource;
use App\Site\Sources\JsonStockistSource;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use Tests\TestCase;

/**
 * The JSON files behind the public website are hand-edited, so every entry
 * is validated and a mistake names the offending index.
 */
class SiteDataSourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/site-data-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function stockistsFile(string $json): JsonStockistSource
    {
        File::put("{$this->dir}/stockists.json", $json);

        return new JsonStockistSource("{$this->dir}/stockists.json");
    }

    private function productsFile(string $json): JsonProductSource
    {
        File::put("{$this->dir}/products.json", $json);
        File::put("{$this->dir}/photo.jpg", 'not really a jpeg');

        return new JsonProductSource("{$this->dir}/products.json", $this->dir);
    }

    // ── Stockists ─────────────────────────────────────────────────────

    public function test_a_valid_stockists_file_parses_and_show_defaults_to_true(): void
    {
        $source = $this->stockistsFile(json_encode(['_note' => 'ignored', 'stockists' => [
            ['name' => ' Lilliput Stores ', 'county' => 'Dublin', 'address' => '5 Rosemount Terrace', 'phone' => '01 672 9516', 'products' => ['milk', 'cheese'], 'website' => null],
            ['name' => 'Hidden Shop', 'county' => 'Cork', 'products' => ['cheese'], 'show' => false],
            ['name' => 'Bare Minimum', 'county' => 'Cavan', 'products' => []],
        ]]));

        $all = $source->all();

        $this->assertCount(2, $all, 'show:false is excluded');
        $first = $all[0];
        $this->assertInstanceOf(Stockist::class, $first);
        $this->assertSame('Lilliput Stores', $first->name, 'trimmed');
        $this->assertSame(['milk', 'cheese'], $first->products);
        $this->assertNull($first->website);
        $this->assertNull($all[1]->address);
    }

    public function test_a_stockist_without_a_county_fails_naming_the_index(): void
    {
        $source = $this->stockistsFile(json_encode(['stockists' => [
            ['name' => 'Fine', 'county' => 'Cork', 'products' => []],
            ['name' => 'Broken', 'products' => []],
        ]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("stockists.json[1]: missing 'county'");
        $source->all();
    }

    public function test_an_unknown_stockist_product_is_rejected(): void
    {
        $source = $this->stockistsFile(json_encode(['stockists' => [
            ['name' => 'Shop', 'county' => 'Cork', 'products' => ['butter']],
        ]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown product 'butter'");
        $source->all();
    }

    public function test_malformed_json_throws(): void
    {
        $source = $this->stockistsFile('{"stockists": [');

        $this->expectException(JsonException::class);
        $source->all();
    }

    // ── Products ──────────────────────────────────────────────────────

    public function test_a_valid_products_file_parses_and_resolves_images(): void
    {
        $source = $this->productsFile(json_encode(['products' => [
            ['slug' => 'organic-milk', 'name' => 'Organic Milk', 'type' => 'milk', 'description' => "One.\n\nTwo.", 'image' => 'photo.jpg', 'sort' => 10],
            ['slug' => 'cumin-cheese', 'name' => 'Cumin', 'type' => 'cheese', 'description' => 'Spiced.'],
            ['slug' => 'old', 'name' => 'Old', 'type' => 'cheese', 'description' => 'Gone.', 'show' => false],
        ]]));

        $all = $source->all();

        $this->assertCount(2, $all);
        $milk = $all[0];
        $this->assertInstanceOf(SiteProduct::class, $milk);
        $this->assertSame("{$this->dir}/photo.jpg", $milk->imageSource);
        $this->assertSame(['One.', 'Two.'], $milk->paragraphs());
        $this->assertSame(10, $milk->sort);
        $this->assertNull($all[1]->imageSource);
        $this->assertSame(0, $all[1]->sort);
    }

    public function test_a_product_with_an_unknown_type_is_rejected(): void
    {
        $source = $this->productsFile(json_encode(['products' => [
            ['slug' => 'butter', 'name' => 'Butter', 'type' => 'butter', 'description' => 'Nope.'],
        ]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("products.json[0]: unknown type 'butter'");
        $source->all();
    }

    public function test_a_product_whose_image_is_missing_is_rejected(): void
    {
        $source = $this->productsFile(json_encode(['products' => [
            ['slug' => 'milk', 'name' => 'Milk', 'type' => 'milk', 'description' => 'x', 'image' => 'nowhere.jpg'],
        ]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("image 'nowhere.jpg' not found");
        $source->all();
    }

    public function test_a_product_slug_must_be_url_safe(): void
    {
        $source = $this->productsFile(json_encode(['products' => [
            ['slug' => 'Organic Milk', 'name' => 'Milk', 'type' => 'milk', 'description' => 'x'],
        ]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slug');
        $source->all();
    }
}
