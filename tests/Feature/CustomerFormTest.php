<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cycle 15 (UX review 2026-10-13, D20/D21/D26): email, city, postal code and
 * credit limit are optional on the customer form; contact fields are tidied
 * on save; the list searches as you type.
 */
class CustomerFormTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Corner Shop',
            'email' => 'shop@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'country' => 'Ireland',
            'credit_limit' => '',
            'payment_terms' => 'immediate',
            'invoice_delivery' => 'email',
            'invoicing_mode' => 'per_order',
            'is_active' => '1',
        ], $overrides);
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Corner Shop', 'email' => null,
            'address' => 'Main St', 'city' => null, 'postal_code' => null, 'is_active' => true,
        ], $attrs));
    }

    public function test_a_print_customer_needs_no_email_city_or_postal_code(): void
    {
        $response = $this->actingAs($this->office)->post(route('customers.store'), $this->form([
            'invoice_delivery' => 'print', 'email' => '', 'city' => '', 'postal_code' => '',
        ]));

        $customer = Customer::sole();
        $response->assertRedirect(route('customers.show', $customer));
        $this->assertNull($customer->email);
        $this->assertNull($customer->city);
        $this->assertNull($customer->postal_code);
    }

    public function test_an_email_customer_still_needs_an_email(): void
    {
        $this->actingAs($this->office)->post(route('customers.store'), $this->form(['email' => '']))
            ->assertSessionHasErrors(['email' => 'An email address is needed while Invoice delivery is Email — or choose Print / post or No invoice sent.']);

        $this->assertSame(0, Customer::count());
    }

    public function test_an_absent_delivery_select_means_email_and_still_needs_an_email(): void
    {
        $form = $this->form(['email' => '']);
        unset($form['invoice_delivery']);

        $this->actingAs($this->office)->post(route('customers.store'), $form)
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Invoice delivery is Email', session('errors')->first('email'));
        $this->assertSame(0, Customer::count());
    }

    public function test_an_absent_delivery_select_on_edit_keeps_a_stored_email_preference(): void
    {
        $customer = $this->customer(['email' => 'shop@example.ie', 'invoice_delivery' => 'email']);
        $form = $this->form(['email' => '']);
        unset($form['invoice_delivery']);

        $this->actingAs($this->office)->put(route('customers.update', $customer), $form)
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Invoice delivery is Email', session('errors')->first('email'));
        $this->assertSame('shop@example.ie', $customer->refresh()->email);
    }

    public function test_an_absent_delivery_select_on_edit_keeps_a_stored_print_preference(): void
    {
        $customer = $this->customer(['email' => 'shop@example.ie', 'invoice_delivery' => 'print']);
        $form = $this->form(['email' => '']);
        unset($form['invoice_delivery']);

        $this->actingAs($this->office)->put(route('customers.update', $customer), $form)
            ->assertSessionHasNoErrors();

        $this->assertNull($customer->refresh()->email);
    }

    public function test_contact_fields_are_trimmed_of_trailing_commas_and_blanks_are_null(): void
    {
        $this->actingAs($this->office)->post(route('customers.store'), $this->form([
            'address' => 'Kilmantin Hill,,', 'city' => ' Wicklow , ', 'postal_code' => '',
        ]))->assertSessionHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame('Kilmantin Hill', $customer->address);
        $this->assertSame('Wicklow', $customer->city);
        $this->assertNull($customer->postal_code);
    }

    public function test_a_blank_credit_limit_is_stored_as_no_limit(): void
    {
        $this->actingAs($this->office)->post(route('customers.store'), $this->form(['credit_limit' => '']))
            ->assertSessionHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame('0.00', $customer->getRawOriginal('credit_limit'));

        $this->actingAs($this->office)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('No limit');
    }

    public function test_blanking_city_and_postal_code_on_edit_stores_null(): void
    {
        $customer = $this->customer(['email' => 'shop@example.ie', 'city' => 'Wicklow', 'postal_code' => 'A67']);

        $this->actingAs($this->office)->put(route('customers.update', $customer), $this->form([
            'city' => '', 'postal_code' => '',
        ]))->assertRedirect(route('customers.show', $customer));

        $customer->refresh();
        $this->assertNull($customer->city);
        $this->assertNull($customer->postal_code);
    }

    public function test_two_email_less_customers_are_not_flagged_as_sharing_an_email(): void
    {
        $this->customer(['name' => 'First Shop']);

        $this->actingAs($this->office)->post(route('customers.store'), $this->form([
            'name' => 'Second Shop', 'invoice_delivery' => 'print', 'email' => '',
        ]))->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');
    }

    public function test_full_address_skips_blank_parts_and_the_docket_prints_no_empty_commas(): void
    {
        $customer = $this->customer(['country' => 'Ireland']);

        $this->assertSame('Main St, Ireland', $customer->full_address);
        $this->assertStringNotContainsString(', ,', $customer->full_address);

        $order = Order::create([
            'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $this->actingAs($this->office)->get(route('orders.docket', $order))
            ->assertOk()
            ->assertSee('Main St, Ireland')
            ->assertDontSee(', ,');
    }

    public function test_an_email_less_customer_renders_on_edit_show_and_index(): void
    {
        $customer = $this->customer(['invoice_delivery' => 'print']);

        $this->actingAs($this->office)->get(route('customers.edit', $customer))->assertOk();
        $this->actingAs($this->office)->get(route('customers.show', $customer))->assertOk();
        $this->actingAs($this->office)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('<div>—</div>', false);
    }

    public function test_the_list_searches_as_you_type(): void
    {
        $this->actingAs($this->office)->get(route('customers.index', ['search' => 'Avo']))
            ->assertOk()
            ->assertSee('x-on:input.debounce', false)
            ->assertSee('requestSubmit()', false);
    }
}
