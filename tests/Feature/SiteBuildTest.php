<?php

namespace Tests\Feature;

use App\Site\Contracts\ProductSource;
use App\Site\Contracts\StockistSource;
use App\Site\Data\SiteProduct;
use App\Site\Data\Stockist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `php artisan site:build` renders the public website (www.mossfield.ie) to
 * static files. No database: the sources are swapped for in-memory ones,
 * and the last test builds from the shipped JSON to prove it parses.
 */
class SiteBuildTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/site-build-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param  list<Stockist>  $items */
    private function stockists(array $items): void
    {
        $this->app->instance(StockistSource::class, new class($items) implements StockistSource
        {
            public function __construct(private array $items) {}

            public function all(): Collection
            {
                return collect($this->items);
            }
        });
    }

    /** @param  list<SiteProduct>  $items */
    private function products(array $items): void
    {
        $this->app->instance(ProductSource::class, new class($items) implements ProductSource
        {
            public function __construct(private array $items) {}

            public function all(): Collection
            {
                return collect($this->items);
            }
        });
    }

    private function build(bool $clean = false): void
    {
        $options = ['--out' => $this->dir] + ($clean ? ['--clean' => true] : []);
        $this->artisan('site:build', $options)->assertSuccessful();
    }

    private function read(string $file): string
    {
        $this->assertFileExists("{$this->dir}/{$file}");

        return (string) file_get_contents("{$this->dir}/{$file}");
    }

    private function shop(string $name, string $county, array $products = ['milk']): Stockist
    {
        return new Stockist($name, $county, "1 Main Street, {$county}", '01 234 5678', $products);
    }

    private function product(string $slug, string $type, ?string $image = null, int $sort = 0): SiteProduct
    {
        return new SiteProduct($slug, ucfirst($slug), $type, "About {$slug}.\n\nSecond paragraph.", $image, $sort);
    }

    // ── Output ────────────────────────────────────────────────────────

    public function test_the_command_writes_every_page_and_the_assets(): void
    {
        $this->stockists([]);
        $this->products([]);

        $this->build();

        foreach (['index.html', 'products.html', 'stockists.html', 'awards.html', 'contact.html', '404.html', 'sitemap.xml', 'robots.txt', '.htaccess', 'assets/css/site.css', 'assets/images/header-6.jpg'] as $file) {
            $this->assertFileExists("{$this->dir}/{$file}", $file);
        }
    }

    public function test_stockists_are_grouped_by_county_and_sorted(): void
    {
        $this->stockists([
            $this->shop('Galway Deli', 'Galway'),
            $this->shop('Zed Stores', 'Cork'),
            $this->shop('Cavan Grocer', 'Cavan'),
            $this->shop('Apple Deli', 'Cork', ['milk', 'cheese']),
        ]);
        $this->products([]);

        $this->build();
        $html = $this->read('stockists.html');

        $cavan = strpos($html, 'id="county-cavan"');
        $cork = strpos($html, 'id="county-cork"');
        $galway = strpos($html, 'id="county-galway"');
        $this->assertTrue($cavan < $cork && $cork < $galway, 'counties in alphabetical order');
        $this->assertTrue(strpos($html, 'Apple Deli') < strpos($html, 'Zed Stores'), 'shops sorted by name within a county');
        $this->assertStringContainsString('badge--cheese', $html);
        $this->assertStringContainsString('href="#county-cork"', $html, 'county jump list');
        $this->assertStringNotContainsString('Looking for your nearest stockist', $html);
    }

    public function test_an_empty_stockist_list_renders_the_contact_note(): void
    {
        $this->stockists([]);
        $this->products([]);

        $this->build();
        $html = $this->read('stockists.html');

        $this->assertStringContainsString('Looking for your nearest stockist?', $html);
        $this->assertStringContainsString('href="contact.html"', $html);
        $this->assertStringNotContainsString('id="county-', $html);
    }

    public function test_products_are_grouped_by_type_with_images_copied_under_their_slug(): void
    {
        $this->stockists([]);
        $this->products([
            $this->product('cumin-cheese', 'cheese', null, 20),
            $this->product('organic-milk', 'milk', resource_path('site/product-images/milk.jpg'), 10),
        ]);

        $this->build();
        $html = $this->read('products.html');

        $this->assertFileExists("{$this->dir}/assets/images/products/organic-milk.jpg");
        $this->assertStringContainsString('src="assets/images/products/organic-milk.jpg"', $html);
        $this->assertStringContainsString('product__img--cheese', $html, 'a product without a photo gets a placeholder');
        $this->assertStringContainsString('<p>About cumin-cheese.</p>', $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html, 'blank line splits paragraphs');
        $this->assertTrue(strpos($html, 'id="organic-milk"') < strpos($html, 'id="cumin-cheese"'), 'milk section before cheese');
    }

    public function test_old_urls_redirect_and_missing_pages_get_the_404(): void
    {
        $this->stockists([]);
        $this->products([]);

        $this->build();
        $htaccess = $this->read('.htaccess');

        $this->assertStringContainsString('ErrorDocument 404 /404.html', $htaccess);
        $this->assertStringContainsString('RedirectMatch 301 ^/wheretobuy\.html$ /stockists.html', $htaccess);
        $this->assertStringContainsString('RedirectMatch 301 ^/Products\.html$ /products.html', $htaccess);
        $this->assertStringContainsString('RedirectMatch 301 ^/contactus\.html$ /contact.html', $htaccess);
        $this->assertStringContainsString('href="index.html"', $this->read('404.html'));
    }

    public function test_pages_carry_the_farm_content(): void
    {
        $this->stockists([]);
        $this->products([]);

        $this->build();

        $index = $this->read('index.html');
        foreach (['six miles from Birr', 'since 1970', 'organic farming in 1999', 'Not homogenised'] as $text) {
            $this->assertStringContainsString($text, $index);
        }
        $contact = $this->read('contact.html');
        foreach (['mailto:info@mossfield.ie', 'tel:0579131002', 'Clareen'] as $text) {
            $this->assertStringContainsString($text, $contact);
        }
        $this->assertStringContainsString('Waitrose Trophy', $this->read('awards.html'));
    }

    public function test_output_is_independent_of_the_app(): void
    {
        $this->stockists([$this->shop('Shop', 'Offaly')]);
        $this->products([$this->product('organic-milk', 'milk', resource_path('site/product-images/milk.jpg'))]);

        $this->build();

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mossfield.local';
        foreach (File::glob("{$this->dir}/*.html") as $path) {
            $html = (string) file_get_contents($path);
            foreach ([$appHost, '/build/', '/storage/', 'csrf', '127.0.0.1', 'localhost'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, basename($path)." must not contain '{$needle}'");
            }
        }
    }

    public function test_every_internal_link_and_image_resolves(): void
    {
        $this->stockists([$this->shop('Shop', 'Offaly')]);
        $this->products([$this->product('organic-milk', 'milk', resource_path('site/product-images/milk.jpg'))]);

        $this->build();

        $checked = 0;
        foreach (File::glob("{$this->dir}/*.html") as $path) {
            preg_match_all('/\b(?:href|src)="([^"]+)"/', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $target) {
                if (preg_match('#^(https?:|mailto:|tel:|\#)#', $target)) {
                    continue;
                }
                $file = strtok($target, '#');
                $this->assertFileExists("{$this->dir}/{$file}", basename($path)." links to missing {$target}");
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
    }

    public function test_the_sitemap_lists_exactly_the_public_pages(): void
    {
        $this->stockists([]);
        $this->products([]);

        $this->build();
        $xml = $this->read('sitemap.xml');

        $this->assertStringStartsWith('<?xml version="1.0"', $xml);
        $base = config('site.base_url');
        foreach (array_keys(config('site.pages')) as $file) {
            $expected = $file === 'index.html' ? "{$base}/" : "{$base}/{$file}";
            $this->assertStringContainsString("<loc>{$expected}</loc>", $xml);
        }
        $this->assertSame(count(config('site.pages')), substr_count($xml, '<loc>'));
        $this->assertStringNotContainsString('404', $xml);
        $this->assertStringContainsString("Sitemap: {$base}/sitemap.xml", $this->read('robots.txt'));
    }

    public function test_clean_removes_stale_files_and_a_plain_build_keeps_them(): void
    {
        $this->stockists([]);
        $this->products([]);
        File::ensureDirectoryExists($this->dir);
        File::put("{$this->dir}/old.html", 'stale');

        $this->build();
        $this->assertFileExists("{$this->dir}/old.html");

        $this->build(clean: true);
        $this->assertFileDoesNotExist("{$this->dir}/old.html");
        $this->assertFileExists("{$this->dir}/index.html");
    }

    public function test_the_shipped_data_files_build(): void
    {
        $this->build();

        $this->assertStringContainsString('Organic Milk', $this->read('products.html'));
        $this->assertFileExists("{$this->dir}/assets/images/products/organic-milk.jpg");
    }
}
