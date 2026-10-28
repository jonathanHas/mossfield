<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Mossorders surface (nav item, dashboard button + sync strip, customer
 * link fields) renders only when MOSSORDERS_UI_ENABLED is on (UX review A5).
 * The routes stay live either way.
 */
class OnlineOrdersVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Linked Shop', 'email' => 'linked@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67',
            'is_active' => true, 'mossorders_user_id' => 4242,
        ]);
    }

    public function test_hidden_by_default(): void
    {
        $office = User::factory()->create();
        $customer = $this->customer();

        $this->assertFalse((bool) config('services.mossorders.ui_enabled'));

        $this->actingAs($office)->get(route('dashboard'))->assertOk()
            ->assertDontSee(route('online-orders.index'), false)
            ->assertDontSee('Online order sync')
            ->assertDontSee('Online Orders');

        $this->actingAs($office)->get(route('customers.index'))->assertOk()
            ->assertDontSee('Online account')
            ->assertDontSee('Online · 4242');

        $this->actingAs($office)->get(route('customers.show', $customer))->assertOk()
            ->assertDontSee('Linked · ID 4242');

        $this->actingAs($office)->get(route('customers.edit', $customer))->assertOk()
            ->assertDontSee('Online integration (Mossorders link)');

        $this->actingAs($office)->get(route('customers.create'))->assertOk()
            ->assertDontSee('Online integration (Mossorders link)');
    }

    public function test_shown_when_the_flag_is_on(): void
    {
        config(['services.mossorders.ui_enabled' => true]);
        $office = User::factory()->create();
        $customer = $this->customer();

        // No sync cache seeded: the strip renders its "never completed" branch.
        $this->actingAs($office)->get(route('dashboard'))->assertOk()
            ->assertSee(route('online-orders.index'), false)
            ->assertSee('Online order sync')
            ->assertSee('has not completed successfully yet');

        $this->actingAs($office)->get(route('customers.index'))->assertOk()
            ->assertSee('Online account')
            ->assertSee('Online · 4242');

        $this->actingAs($office)->get(route('customers.show', $customer))->assertOk()
            ->assertSee('Linked · ID 4242');

        $this->actingAs($office)->get(route('customers.edit', $customer))->assertOk()
            ->assertSee('Online integration (Mossorders link)');
    }

    public function test_routes_stay_reachable_with_the_flag_off(): void
    {
        $this->actingAs(User::factory()->create())->get(route('online-orders.index'))->assertOk();
    }

    public function test_saving_a_customer_with_the_field_hidden_keeps_the_link(): void
    {
        $office = User::factory()->create();
        $customer = $this->customer();

        $this->actingAs($office)->patch(route('customers.update', $customer), [
            'name' => 'Linked Shop', 'email' => 'linked@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67',
            'country' => 'Ireland', 'credit_limit' => '0', 'payment_terms' => 'immediate',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame(4242, (int) $customer->fresh()->mossorders_user_id);
    }
}
