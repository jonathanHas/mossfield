<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin-tunable settings (cycle 10): config holds the defaults, a `settings`
 * row overrides one. First user: the batch sanity ceilings.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    // ── The store ─────────────────────────────────────────────────────

    public function test_get_falls_back_to_the_default(): void
    {
        $this->assertNull(Setting::get('nothing.here'));
        $this->assertSame(7, Setting::get('nothing.here', 7));
        $this->assertSame(200, Setting::batchCeiling('cheese'), 'config default');
        $this->assertSame(5000, Setting::batchCeiling('milk'));
    }

    public function test_a_stored_value_wins_and_survives_a_fresh_read(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(200, Setting::batchCeiling('cheese')); // warm the cache

        Setting::set('batch_ceilings.cheese', 500, $admin);

        $this->assertSame(500, Setting::batchCeiling('cheese'), 'set() clears the cache');
        Setting::flushCache();
        $this->assertSame(500, Setting::get('batch_ceilings.cheese'), 'from the database again');
        $this->assertSame($admin->id, Setting::find('batch_ceilings.cheese')->updated_by);
        $this->assertSame('500', DB::table('settings')->where('key', 'batch_ceilings.cheese')->value('value'), 'stored as JSON');

        Setting::forget('batch_ceilings.cheese');
        $this->assertSame(200, Setting::batchCeiling('cheese'));
    }

    public function test_settings_are_read_in_one_query_per_request(): void
    {
        Setting::set('batch_ceilings.milk', 4000);
        Setting::flushCache();

        DB::enableQueryLog();
        foreach (['milk', 'yoghurt', 'cheese', 'milk', 'cheese'] as $type) {
            Setting::batchCeiling($type);
        }
        $this->assertCount(1, DB::getQueryLog());
    }

    // ── The page ──────────────────────────────────────────────────────

    private function ceilings(int $milk = 5000, int $yoghurt = 3000, int $cheese = 200): array
    {
        return ['batch_ceilings' => compact('milk', 'yoghurt', 'cheese')];
    }

    public function test_an_admin_sees_the_defaults(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Batch quantity ceilings')
            ->assertSee('Above these a new batch asks for confirmation before saving.')
            ->assertSee('name="batch_ceilings[cheese]"', false)
            ->assertSee('value="200"', false)
            ->assertSee('value="5000"', false)
            ->assertSee('default 5 000')
            ->assertDontSee('changed by')
            ->assertDontSee('Reset to defaults')
            ->assertSee(route('settings.index'), false); // the Admin nav link
    }

    public function test_saving_stores_the_change_and_names_it(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Jon Admin']);

        $this->actingAs($admin)->put(route('settings.update'), $this->ceilings(cheese: 500))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Batch ceilings saved — cheese 200 → 500.');

        $this->assertSame(500, Setting::batchCeiling('cheese'));
        $this->assertSame(1, Setting::count(), 'only the changed value is stored');

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('value="500"', false)
            ->assertSee('changed by Jon Admin on '.today()->format('d/m/Y'))
            ->assertSee('Reset to defaults');

        $this->actingAs($admin)->put(route('settings.update'), $this->ceilings(cheese: 500))
            ->assertSessionHas('success', 'No changes.');
        $this->actingAs($admin)->put(route('settings.update'), $this->ceilings(milk: 4000, cheese: 250))
            ->assertSessionHas('success', 'Batch ceilings saved — milk 5000 → 4000, cheese 500 → 250.');
    }

    public function test_a_ceiling_must_be_a_whole_number_of_at_least_one(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([0, -5, '', 'abc', 2.5, 1000001] as $bad) {
            $this->actingAs($admin)->from(route('settings.index'))
                ->put(route('settings.update'), ['batch_ceilings' => ['milk' => 5000, 'yoghurt' => 3000, 'cheese' => $bad]])
                ->assertRedirect(route('settings.index'))
                ->assertSessionHasErrors('batch_ceilings.cheese');
        }
        $this->assertSame(0, Setting::count());
    }

    public function test_reset_restores_the_defaults(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('settings.update'), $this->ceilings(milk: 100, yoghurt: 100, cheese: 100));
        $this->assertSame(3, Setting::count());

        $this->actingAs($admin)->put(route('settings.update'), ['reset' => 1])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Batch ceilings reset to the defaults.');

        $this->assertSame(0, Setting::count());
        $this->assertSame(200, Setting::batchCeiling('cheese'));
    }

    public function test_only_admins_reach_settings(): void
    {
        foreach (['office' => User::factory()->create(), 'factory' => User::factory()->factoryWorker()->create()] as $role => $user) {
            $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
            $this->actingAs($user)->put(route('settings.update'), $this->ceilings(cheese: 1))->assertForbidden();
            $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('settings.index'), false);
        }
        $this->assertSame(0, Setting::count());

        auth()->logout();
        $this->get(route('settings.index'))->assertRedirect(route('login'));

        // An inactive admin is out too (EnsureUserIsActive + the policy).
        $this->actingAs(User::factory()->admin()->create(['is_active' => false]))
            ->get(route('settings.index'))->assertRedirect(route('login'));
    }
}
