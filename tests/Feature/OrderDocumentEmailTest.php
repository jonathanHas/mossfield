<?php

namespace Tests\Feature;

use App\Mail\OrderDocumentMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email documents — POST /orders/{order}/email/{document}. Sends the invoice or dispatch
 * docket to the customer as a PDF attachment. Office/admin only (factory denied at the
 * route group). Invoices carry the same see-financials + hasReachedReady() guards as the
 * on-screen invoice; both require a customer email on file.
 */
class OrderDocumentEmailTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create(['name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Whole Wheel', 'size' => 'x', 'unit' => 'wheel',
            'weight_kg' => 4.0, 'base_price' => 12.50, 'is_active' => true,
        ]);
        $this->customer = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);

        $this->order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => 'ready',
            'payment_status' => 'pending',
            'subtotal' => 25.00, 'tax_amount' => 0, 'total_amount' => 25.00,
        ]);
        $this->order->orderItems()->create([
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 2,
            'unit_price' => $variant->base_price,
        ]);
    }

    public function test_office_emails_the_invoice_to_the_customer(): void
    {
        Mail::fake();
        $office = User::factory()->create(); // default role is office

        $this->actingAs($office)
            ->post(route('orders.email', [$this->order, 'invoice']))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(OrderDocumentMail::class, function (OrderDocumentMail $mail) {
            return $mail->hasTo('k@example.ie')
                && $mail->document === 'invoice'
                && $mail->order->is($this->order);
        });
    }

    public function test_office_emails_the_docket_to_the_customer(): void
    {
        Mail::fake();
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('orders.email', [$this->order, 'docket']))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(OrderDocumentMail::class, fn (OrderDocumentMail $mail) => $mail->document === 'docket');
    }

    public function test_emailed_invoice_carries_a_pdf_attachment(): void
    {
        Mail::fake();
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('orders.email', [$this->order, 'invoice']));

        Mail::assertSent(OrderDocumentMail::class, function (OrderDocumentMail $mail) {
            $attachments = $mail->attachments();

            return count($attachments) === 1
                && $attachments[0]->as === 'invoice-'.$this->order->order_number.'.pdf'
                && $attachments[0]->mime === 'application/pdf';
        });
    }

    public function test_invoice_email_blocked_before_the_order_reaches_ready(): void
    {
        Mail::fake();
        $office = User::factory()->create();
        $this->order->update(['status' => 'confirmed']);

        $this->actingAs($office)
            ->post(route('orders.email', [$this->order, 'invoice']))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_email_blocked_when_customer_has_no_email(): void
    {
        Mail::fake();
        $office = User::factory()->create();
        $this->customer->update(['email' => '']);

        $this->actingAs($office)
            ->post(route('orders.email', [$this->order, 'docket']))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_unknown_document_type_is_not_found(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('orders.email', [$this->order, 'receipt']))
            ->assertNotFound();
    }

    public function test_factory_is_denied_emailing(): void
    {
        Mail::fake();
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->post(route('orders.email', [$this->order, 'docket']))
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
