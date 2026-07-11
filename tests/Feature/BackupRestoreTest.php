<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProductVariant $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'base_price' => 1.50, 'is_active' => true,
        ]);
    }

    private function seedBusinessData(): Customer
    {
        $customer = Customer::create([
            'name' => 'Dublin Market',
            'email' => 'orders@dublinmarket.ie',
            'phone' => '+353 1 555 0100',
            'address' => 'Temple Bar', 'city' => 'Dublin', 'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);

        $batch = Batch::create([
            'product_id' => $this->bottle->product_id,
            'batch_code' => 'M010726', 'production_date' => '2026-07-01',
            'raw_milk_litres' => 200, 'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $this->bottle->id,
            'quantity_produced' => 100, 'quantity_remaining' => 100,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-20260701-001',
            'customer_id' => $customer->id,
            'order_date' => '2026-07-01', 'delivery_date' => '2026-07-02',
            'status' => 'pending', 'payment_status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => 10, 'unit_price' => 1.50,
        ]);

        return $customer;
    }

    // --- Access control -----------------------------------------------------

    public function test_admin_can_view_the_backup_page(): void
    {
        $this->actingAs($this->admin)->get(route('backup.index'))->assertOk();
    }

    public function test_office_and_factory_are_denied(): void
    {
        foreach ([User::factory()->create(), User::factory()->factoryWorker()->create()] as $user) {
            $this->actingAs($user)->get(route('backup.index'))->assertForbidden();
            $this->actingAs($user)->get(route('backup.download'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.restore'))->assertForbidden();
        }
    }

    // --- Download -----------------------------------------------------------

    public function test_download_returns_a_json_attachment_with_every_table(): void
    {
        $this->seedBusinessData();

        $response = $this->actingAs($this->admin)->get(route('backup.download'));

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.json', $response->headers->get('content-disposition'));

        $payload = json_decode($response->streamedContent(), true);
        $this->assertArrayHasKey('meta', $payload);
        foreach (\App\Services\BackupService::TABLES as $table) {
            $this->assertArrayHasKey($table, $payload['tables']);
        }
        $this->assertCount(1, $payload['tables']['orders']);
    }

    // --- Round-trip restore -------------------------------------------------

    public function test_full_replace_restore_reverts_the_database(): void
    {
        $customer = $this->seedBusinessData();
        $backup = $this->makeBackupFile();

        // Mutate: delete the order + customer, add a stray order.
        Order::query()->delete();
        $customer->delete();
        $this->assertSame(0, Order::count());

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), ['backup_file' => $backup, 'confirm' => 'RESTORE'])
            ->assertRedirect(route('backup.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Customer::where('name', 'Dublin Market')->count());
        $this->assertDatabaseHas('order_items', ['order_id' => Order::first()->id, 'quantity_ordered' => 10]);
    }

    public function test_restore_preserves_encrypted_customer_pii(): void
    {
        $this->seedBusinessData();
        $backup = $this->makeBackupFile();

        Customer::query()->delete();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), ['backup_file' => $backup, 'confirm' => 'RESTORE']);

        $restored = Customer::where('name', 'Dublin Market')->first();
        $this->assertSame('+353 1 555 0100', $restored->phone); // decrypts cleanly
    }

    public function test_restore_requires_the_typed_confirmation(): void
    {
        $this->seedBusinessData();
        $backup = $this->makeBackupFile();
        Order::query()->delete();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), ['backup_file' => $backup, 'confirm' => 'yes'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, Order::count()); // untouched
    }

    public function test_restore_aborts_when_backup_has_no_active_admin(): void
    {
        $this->seedBusinessData();
        $payload = app(\App\Services\BackupService::class)->export();

        // Strip all admins from the payload.
        $payload['tables']['users'] = array_values(array_filter(
            $payload['tables']['users'],
            fn ($u) => $u['role'] !== 'admin'
        ));
        $backup = UploadedFile::fake()->createWithContent('backup.json', json_encode($payload));

        $ordersBefore = Order::count();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), ['backup_file' => $backup, 'confirm' => 'RESTORE'])
            ->assertSessionHas('error');

        $this->assertSame($ordersBefore, Order::count()); // rolled back
    }

    public function test_restore_rolls_back_on_malformed_payload(): void
    {
        $this->seedBusinessData();
        // Valid JSON but wrong shape (missing tables).
        $backup = UploadedFile::fake()->createWithContent('backup.json', json_encode(['meta' => [], 'tables' => []]));

        $before = DB::table('orders')->count();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), ['backup_file' => $backup, 'confirm' => 'RESTORE'])
            ->assertSessionHas('error');

        $this->assertSame($before, DB::table('orders')->count());
    }

    private function makeBackupFile(): UploadedFile
    {
        $payload = app(\App\Services\BackupService::class)->export();

        return UploadedFile::fake()->createWithContent(
            'mossfield-backup.json',
            json_encode($payload)
        );
    }
}
