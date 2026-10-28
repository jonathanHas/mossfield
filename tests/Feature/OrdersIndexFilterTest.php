<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cycle 15 (UX review 2026-10-13, D25): the Orders list searches, takes a
 * delivery-date range, applies filters as they change, and threads the same
 * filters into the order page's sibling pane.
 */
class OrdersIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Order $a;

    private Order $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();

        $barnhill = $this->customer('Barnhill Stores');
        $avoca = $this->customer('Avoca Rathcoole');

        $this->a = $this->order($barnhill, ['customer_reference' => 'PO-77', 'delivery_date' => '2026-11-03', 'status' => 'ready']);
        $this->b = $this->order($avoca, ['delivery_date' => '2026-11-10', 'status' => 'cancelled']);
    }

    private function customer(string $name): Customer
    {
        return Customer::create([
            'name' => $name, 'email' => uniqid().'@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true,
        ]);
    }

    private function order(Customer $customer, array $attrs): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ], $attrs));
    }

    private function list(array $query)
    {
        return $this->actingAs($this->office)->get(route('orders.index', $query))->assertOk();
    }

    public function test_search_matches_po_ref_customer_name_and_order_number(): void
    {
        $this->list(['q' => 'PO-77'])->assertSee($this->a->order_number)->assertDontSee($this->b->order_number);
        $this->list(['q' => 'Avoca'])->assertSee($this->b->order_number)->assertDontSee($this->a->order_number);
        $this->list(['q' => $this->a->order_number])->assertSee($this->a->order_number)->assertDontSee($this->b->order_number);
    }

    public function test_delivery_date_range_and_an_unreadable_date_is_ignored(): void
    {
        $this->list(['from' => '2026-11-05'])->assertSee($this->b->order_number)->assertDontSee($this->a->order_number);
        $this->list(['to' => '2026-11-05'])->assertSee($this->a->order_number)->assertDontSee($this->b->order_number);
        $this->list(['from' => 'nonsense'])->assertSee($this->a->order_number)->assertSee($this->b->order_number);
    }

    public function test_a_cancelled_order_shows_no_payment_tag(): void
    {
        $this->list(['status' => 'cancelled'])
            ->assertSee($this->b->order_number)
            ->assertDontSee('mf-tag-warn">Pending', false);
    }

    public function test_docket_sits_after_view_in_the_actions_cell_and_only_once_ready(): void
    {
        $this->list(['status' => 'ready'])->assertSeeInOrder(['>View</a>', '>Docket</a>'], false);

        $this->order($this->a->customer, ['status' => 'pending']);
        $this->list(['status' => 'pending'])->assertSee('>View</a>', false)->assertDontSee('>Docket</a>', false);
    }

    public function test_the_order_page_sibling_pane_keeps_the_search(): void
    {
        $this->actingAs($this->office)->get(route('orders.show', ['order' => $this->a->id, 'q' => 'Avoca']))
            ->assertOk()
            ->assertViewHas('listFilters', ['q' => 'Avoca'])
            ->assertViewHas('orderList', fn ($list) => $list->contains('id', $this->b->id) && $list->contains('id', $this->a->id));
    }

    public function test_filters_apply_as_they_change(): void
    {
        $this->list([])
            ->assertSee('requestSubmit()', false)
            ->assertSee('x-on:input.debounce', false);
    }
}
