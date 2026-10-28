<?php

namespace Tests\Feature;

use App\Models\User;
use App\View\FinanceNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Cycle 15 (UX review 2026-10-13, E29): the sidebar's Finance group and the
 * finance tab bar are one list.
 */
class FinanceNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_has_eight_real_routes(): void
    {
        $items = FinanceNav::items();

        $this->assertCount(8, $items);
        $this->assertSame(8, count(array_unique(array_column($items, 'id'))));
        foreach ($items as $row) {
            $this->assertTrue(Route::has($row['route']), $row['route'].' is not a route');
        }
    }

    public function test_the_sidebar_lists_every_finance_screen(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('invoicing.consolidated.create'))
            ->assertSee(route('invoicing.emails.index'))
            ->assertSee(route('invoicing.export.index'))
            ->assertSee('To invoice');
    }

    public function test_a_finance_page_links_all_eight(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('invoicing.invoices.index'))->assertOk();

        foreach (FinanceNav::items() as $row) {
            $response->assertSee(route($row['route']));
        }
    }

    public function test_factory_sees_no_finance_links(): void
    {
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('invoicing.emails.index'));
    }
}
