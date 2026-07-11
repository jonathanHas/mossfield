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
use App\Services\BackupArchive;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private User $admin;

    private ProductVariant $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

    /** Build a real encrypted archive from a payload and wrap it as an upload. */
    private function archiveUpload(?array $payload = null, string $password = self::PASSWORD): UploadedFile
    {
        $payload ??= app(BackupService::class)->export();
        $path = app(BackupArchive::class)->build($payload, $password);

        try {
            return UploadedFile::fake()->createWithContent('backup.mfbackup', file_get_contents($path));
        } finally {
            @unlink($path);
        }
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
            $this->actingAs($user)->post(route('backup.download'), ['password' => self::PASSWORD])->assertForbidden();
            $this->actingAs($user)->post(route('backup.restore'))->assertForbidden();
        }
    }

    // --- Download -----------------------------------------------------------

    public function test_download_returns_an_encrypted_attachment(): void
    {
        $this->seedBusinessData();

        $response = $this->actingAs($this->admin)
            ->post(route('backup.download'), ['password' => self::PASSWORD]);

        $response->assertOk();
        $this->assertStringContainsString('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.mfbackup', $response->headers->get('content-disposition'));
    }

    public function test_download_requires_a_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('backup.download'), ['password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    // --- Archive crypto (service level) -------------------------------------

    public function test_archive_round_trips_and_stores_pii_as_plaintext(): void
    {
        $this->seedBusinessData();

        $path = app(BackupArchive::class)->build(app(BackupService::class)->export(), self::PASSWORD);
        try {
            $opened = app(BackupArchive::class)->open($path, self::PASSWORD);
        } finally {
            @unlink($path);
            @unlink($opened['zipPath'] ?? '');
        }

        $this->assertSame(2, $opened['payload']['meta']['format_version']);
        $this->assertSame('plaintext', $opened['payload']['meta']['pii_format']);
        foreach (BackupService::TABLES as $table) {
            $this->assertArrayHasKey($table, $opened['payload']['tables']);
        }
        // Portability proof: contact details sit in the file as readable plaintext.
        $this->assertSame('+353 1 555 0100', $opened['payload']['tables']['customers'][0]['phone']);
    }

    public function test_wrong_password_cannot_open_the_archive(): void
    {
        $this->seedBusinessData();
        $path = app(BackupArchive::class)->build(app(BackupService::class)->export(), self::PASSWORD);

        try {
            $this->expectException(RuntimeException::class);
            app(BackupArchive::class)->open($path, 'the-wrong-password');
        } finally {
            @unlink($path);
        }
    }

    // --- Restore round-trip -------------------------------------------------

    public function test_full_replace_restore_reverts_the_database(): void
    {
        $customer = $this->seedBusinessData();
        $upload = $this->archiveUpload();

        Order::query()->delete();
        $customer->delete();
        $this->assertSame(0, Order::count());

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), [
                'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'RESTORE',
            ])
            ->assertRedirect(route('backup.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Customer::where('name', 'Dublin Market')->count());
        $this->assertDatabaseHas('order_items', ['order_id' => Order::first()->id, 'quantity_ordered' => 10]);
    }

    public function test_restore_reencrypts_customer_pii_readable(): void
    {
        $this->seedBusinessData();
        $upload = $this->archiveUpload();
        Customer::query()->delete();

        $this->actingAs($this->admin)->post(route('backup.restore'), [
            'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'RESTORE',
        ]);

        $restored = Customer::where('name', 'Dublin Market')->first();
        $this->assertSame('+353 1 555 0100', $restored->phone); // decrypts under local key
    }

    public function test_images_are_backed_up_and_restored(): void
    {
        $this->seedBusinessData();
        Storage::disk('public')->put('products/logo.png', 'FAKE-IMAGE-BYTES');

        $upload = $this->archiveUpload(); // captures the image

        Storage::disk('public')->delete('products/logo.png');
        Storage::disk('public')->assertMissing('products/logo.png');

        $this->actingAs($this->admin)->post(route('backup.restore'), [
            'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'RESTORE',
        ]);

        Storage::disk('public')->assertExists('products/logo.png');
        $this->assertSame('FAKE-IMAGE-BYTES', Storage::disk('public')->get('products/logo.png'));
    }

    // --- Guards -------------------------------------------------------------

    public function test_restore_requires_the_typed_confirmation(): void
    {
        $this->seedBusinessData();
        $upload = $this->archiveUpload();
        Order::query()->delete();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), [
                'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'yes',
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, Order::count());
    }

    public function test_restore_with_wrong_password_changes_nothing(): void
    {
        $this->seedBusinessData();
        $upload = $this->archiveUpload();
        $before = Order::count();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), [
                'backup_file' => $upload, 'password' => 'not-the-password', 'confirm' => 'RESTORE',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, Order::count());
    }

    public function test_restore_aborts_when_backup_has_no_active_admin(): void
    {
        $this->seedBusinessData();
        $payload = app(BackupService::class)->export();
        $payload['tables']['users'] = array_values(array_filter(
            $payload['tables']['users'],
            fn ($u) => $u['role'] !== 'admin'
        ));
        $upload = $this->archiveUpload($payload);
        $before = Order::count();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), [
                'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'RESTORE',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, Order::count());
    }

    public function test_restore_rolls_back_on_malformed_payload(): void
    {
        $this->seedBusinessData();
        $upload = $this->archiveUpload(['meta' => ['format_version' => 2], 'tables' => []]);
        $before = Order::count();

        $this->actingAs($this->admin)
            ->post(route('backup.restore'), [
                'backup_file' => $upload, 'password' => self::PASSWORD, 'confirm' => 'RESTORE',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, Order::count());
    }
}
