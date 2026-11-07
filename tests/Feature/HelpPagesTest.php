<?php

namespace Tests\Feature;

use App\Http\Controllers\HelpController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The user guide at /help renders the real docs/guide/ folder: every page the
 * index links to exists, every page renders with its title, every picture a
 * page uses (and every shot in the manifest) is on disk and served, and
 * nothing outside the guide folder can be reached.
 */
class HelpPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private User $factory;

    /** The one role that reads every page (HelpController::AUDIENCE). */
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();
        $this->factory = User::factory()->factoryWorker()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function guide(string $path = ''): string
    {
        return base_path('docs/guide'.($path === '' ? '' : '/'.$path));
    }

    /** @return array<int, string> page names (no .md), README excluded */
    private function pages(): array
    {
        return collect(glob($this->guide('*.md')))
            ->map(fn ($f) => basename($f, '.md'))
            ->reject(fn ($n) => $n === 'README')
            ->values()
            ->all();
    }

    public function test_the_help_index_lists_every_guide_page(): void
    {
        $this->actingAs($this->office)->get(route('help.index'))
            ->assertOk()
            ->assertSee('Mossfield user guide');

        preg_match_all('/\]\(([a-z0-9-]+)\.md\)/', file_get_contents($this->guide('README.md')), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $page) {
            $this->assertFileExists($this->guide("$page.md"), "README links to $page.md");
        }
    }

    public function test_every_guide_page_renders_with_its_title(): void
    {
        $this->assertNotEmpty($this->pages());

        foreach ($this->pages() as $page) {
            preg_match('/^# (.+)$/m', file_get_contents($this->guide("$page.md")), $m);
            $this->assertNotEmpty($m, "$page.md has no '# ' title line");

            $this->actingAs($this->admin)->get("/help/$page")
                ->assertOk()
                ->assertSee('<h1>'.e(trim($m[1])).'</h1>', false);
            $this->actingAs($this->admin)->get("/help/$page.md")->assertOk();
        }
    }

    public function test_every_picture_a_page_uses_exists_and_is_served(): void
    {
        $used = [];
        foreach (glob($this->guide('*.md')) as $file) {
            preg_match_all('/images\/([a-z0-9-]+\.png)/', file_get_contents($file), $m);
            $used = array_merge($used, $m[1]);
        }
        $this->assertNotEmpty($used);

        foreach (array_unique($used) as $png) {
            $this->assertFileExists($this->guide("images/$png"));
            $response = $this->actingAs($this->admin)->get("/help/images/$png");
            $response->assertOk();
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
        }

        // The manifest and the folder cannot drift.
        $manifest = json_decode(file_get_contents($this->guide('screenshots.json')), true);
        foreach ($manifest['shots'] as $shot) {
            $this->assertFileExists($this->guide("images/{$shot['name']}.png"), "shot {$shot['name']} has no PNG");
        }
    }

    public function test_a_page_outside_the_guide_is_not_served(): void
    {
        $this->actingAs($this->office);

        $this->get('/help/planimp')->assertNotFound();
        $this->get('/help/..%2Fplanimp')->assertNotFound();
        $this->get('/help/images/..%2F..%2Fplanimp.md')->assertNotFound();
        $this->get('/help/images/nope.png')->assertNotFound();
    }

    public function test_factory_can_read_the_guide(): void
    {
        $this->actingAs($this->factory)->get(route('help.index'))->assertOk();
        $this->actingAs($this->factory)->get(route('help.show', 'picking'))
            ->assertOk()
            ->assertSee('<h1>Picking</h1>', false);
    }

    public function test_the_sidebar_links_to_help(): void
    {
        $this->actingAs($this->office)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('help.index').'"', false)
            ->assertSee('Help');
    }

    /** @return array<string, array<int, string>> page name => pictures it uses */
    private function picturesByPage(): array
    {
        $by = [];
        foreach (glob($this->guide('*.md')) as $file) {
            preg_match_all('/images\/([a-z0-9-]+\.png)/', file_get_contents($file), $m);
            $by[basename($file, '.md')] = array_values(array_unique($m[1]));
        }

        return $by;
    }

    public function test_every_guide_page_has_an_audience(): void
    {
        $files = collect(glob($this->guide('*.md')))->map(fn ($f) => basename($f, '.md'))->sort()->values()->all();
        $keys = collect(array_keys(HelpController::AUDIENCE))->sort()->values()->all();

        $this->assertSame($files, $keys, 'HelpController::AUDIENCE and docs/guide/*.md must list the same pages');
        foreach (HelpController::AUDIENCE as $page => $audience) {
            $this->assertContains($audience, ['all', 'office', 'admin'], "$page has audience '$audience'");
        }
    }

    /** Which audiences each test user may read. */
    private function readable(User $user): array
    {
        return match (true) {
            $user->is($this->admin) => ['all', 'office', 'admin'],
            $user->is($this->office) => ['all', 'office'],
            default => ['all'],
        };
    }

    public function test_the_floor_cannot_open_office_pages_or_their_pictures(): void
    {
        $byPage = $this->picturesByPage();

        foreach ([$this->factory, $this->office, $this->admin] as $user) {
            $audiences = $this->readable($user);
            $allowedPictures = collect($byPage)
                ->filter(fn ($_, $page) => in_array(HelpController::AUDIENCE[$page], $audiences, true))
                ->flatten()->unique()->all();

            foreach (HelpController::AUDIENCE as $page => $audience) {
                if ($page !== 'README') {
                    $expected = in_array($audience, $audiences, true) ? 200 : 403;
                    $this->actingAs($user)->get("/help/$page")->assertStatus($expected);
                    $this->actingAs($user)->get("/help/$page.md")->assertStatus($expected);
                }
                foreach ($byPage[$page] as $png) {
                    $this->actingAs($user)->get("/help/images/$png")
                        ->assertStatus(in_array($png, $allowedPictures, true) ? 200 : 403, "{$user->role->value}: $png");
                }
            }
        }

        // Each narrower audience really has a picture of its own, so the 403s above are exercised.
        $floor = collect($byPage)->filter(fn ($_, $p) => HelpController::AUDIENCE[$p] === 'all')->flatten()->all();
        $office = collect($byPage)->filter(fn ($_, $p) => HelpController::AUDIENCE[$p] !== 'admin')->flatten()->all();
        $this->assertNotEmpty(array_diff($office, $floor), 'some picture is office-only');
        $this->assertNotEmpty(array_diff(collect($byPage)->flatten()->all(), $office), 'some picture is admin-only');
    }

    public function test_the_floor_index_lists_only_its_pages(): void
    {
        foreach ([$this->factory, $this->office, $this->admin] as $user) {
            $index = $this->actingAs($user)->get(route('help.index'))->assertOk();
            foreach (HelpController::AUDIENCE as $page => $audience) {
                if ($page === 'README') {
                    continue;
                }
                $link = route('help.show', "$page.md");
                in_array($audience, $this->readable($user), true)
                    ? $index->assertSee($link, false)
                    : $index->assertDontSee($link, false);
            }
        }

        // Spelled out once: the floor sees picking, not finance; office does not see admin.
        $this->actingAs($this->factory)->get(route('help.index'))
            ->assertSee(route('help.show', 'picking.md'), false)
            ->assertDontSee(route('help.show', 'finance.md'), false)
            ->assertDontSee(route('help.show', 'admin.md'), false);
        $this->actingAs($this->office)->get(route('help.index'))
            ->assertSee(route('help.show', 'customers.md'), false)
            ->assertDontSee(route('help.show', 'admin.md'), false);
    }
}
