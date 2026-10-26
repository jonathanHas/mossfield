<?php

namespace App\Site;

use App\Site\Contracts\ProductSource;
use App\Site\Contracts\StockistSource;
use App\Site\Data\BuildResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * Renders the public website (www.mossfield.ie) to a directory of static
 * files: copies resources/site/assets, copies product images under their
 * slug, sorts and groups the stockists, and renders each Blade view under
 * resources/views/site to its output file. Output is deterministic (no
 * timestamps) so a future publisher can diff against what is on the host.
 */
final class SiteBuilder
{
    public function __construct(
        private readonly StockistSource $stockists,
        private readonly ProductSource $products,
        private readonly Filesystem $files,
    ) {}

    public function build(string $dir, bool $clean = false): BuildResult
    {
        if ($clean && $this->files->isDirectory($dir)) {
            $this->files->deleteDirectory($dir);
        }

        $this->files->ensureDirectoryExists("{$dir}/assets/images/products");
        $this->files->copyDirectory(config('site.assets_dir'), "{$dir}/assets");

        $products = $this->products->all()->sortBy('sort')->values();
        $productImages = $this->copyProductImages($products, $dir);

        $byCounty = $this->stockists->all()
            ->sortBy([['county', 'asc'], ['name', 'asc']])
            ->groupBy('county');

        $written = [];
        foreach ($this->pages($products, $productImages, $byCounty) as $file => [$view, $data]) {
            $html = view($view, $data + ['site' => config('site'), 'current' => $file])->render();
            $this->files->ensureDirectoryExists(dirname("{$dir}/{$file}"));
            $this->files->put("{$dir}/{$file}", $html);
            $written[] = $file;
        }

        return new BuildResult($dir, $written);
    }

    /**
     * @return array<string, string> slug => path relative to the build dir
     */
    private function copyProductImages(Collection $products, string $dir): array
    {
        $images = [];
        foreach ($products as $product) {
            if ($product->imageSource === null) {
                continue;
            }
            $ext = strtolower(pathinfo($product->imageSource, PATHINFO_EXTENSION)) ?: 'jpg';
            $relative = "assets/images/products/{$product->slug}.{$ext}";
            $this->files->copy($product->imageSource, "{$dir}/{$relative}");
            $images[$product->slug] = $relative;
        }

        return $images;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}> output file => [view, data]
     */
    private function pages(Collection $products, array $productImages, Collection $byCounty): array
    {
        return [
            'index.html' => ['site.index', ['products' => $products, 'productImages' => $productImages]],
            'products.html' => ['site.products', ['byType' => $products->groupBy('type'), 'productImages' => $productImages]],
            'stockists.html' => ['site.stockists', ['byCounty' => $byCounty]],
            'awards.html' => ['site.awards', ['awards' => config('site.awards')]],
            'contact.html' => ['site.contact', []],
            '404.html' => ['site.404', []],
            'sitemap.xml' => ['site.sitemap', []],
            'robots.txt' => ['site.robots', []],
            '.htaccess' => ['site.htaccess', ['redirects' => config('site.redirects')]],
        ];
    }
}
