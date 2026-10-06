<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Destructive actions confirm through the shared in-app dialog
 * (layouts/partials/confirm-dialog.blade.php + resources/js/confirm.js,
 * driven by data-confirm attributes), never the browser's native confirm() —
 * which automated browser sessions cannot drive.
 */
class ConfirmDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_view_uses_the_native_confirm_dialog(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (str_contains($line, 'confirm(')) {
                    $offenders[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "Native confirm() found — use data-confirm instead:\n".implode("\n", $offenders));
    }

    public function test_both_layouts_render_the_shared_dialog(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Other Person']);

        $this->actingAs($admin)->get('/users')
            ->assertOk()
            ->assertSee('id="confirm-action-ok"', false)
            ->assertSee('data-confirm="Deactivate', false);

        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)->get('/picking')
            ->assertOk()
            ->assertSee('id="confirm-action-ok"', false);
    }

    public function test_a_destructive_action_is_marked_danger(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($admin)->get('/users')
            ->assertOk()
            ->assertSee('data-confirm-danger', false);
    }

    public function test_the_cancel_order_dialog_names_both_choices(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Shop', 'email' => 's@example.ie', 'address' => 'Main St', 'city' => 'Bray', 'postal_code' => 'A98', 'is_active' => true,
        ]);
        $order = \App\Models\Order::create([
            'customer_id' => $customer->id, 'order_date' => today(), 'status' => 'pending',
            'payment_status' => 'pending', 'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $this->actingAs(User::factory()->create())->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-confirm-title="Cancel this order?"', false)
            ->assertSee('data-confirm-cancel-label="Keep order"', false)
            ->assertSee('data-confirm-label="Cancel order"', false);
    }

    public function test_every_dialog_names_its_action(): void
    {
        // Split each view on "<" so every chunk is one tag's attributes —
        // tags span several lines, so a per-line scan would miss the title.
        $offenders = [];
        $bareBodies = [];
        $echoes = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = $file->getContents();
            $offset = 0;
            foreach (explode('<', $contents) as $chunk) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $offset += strlen($chunk) + 1;

                if (! str_contains($chunk, 'data-confirm="')) {
                    continue;
                }
                $where = $file->getRelativePathname().':'.$line;

                if (! preg_match('/data-confirm-title="([^"]*)"/', $chunk, $m) || trim($m[1]) === '') {
                    $offenders[] = $where.' (no title)';
                } elseif (stripos($m[1], 'Are you sure') !== false) {
                    $offenders[] = $where.' (title is "Are you sure?")';
                }
                if (preg_match('/data-confirm="\s*Are you sure/i', $chunk)) {
                    $bareBodies[] = $where;
                }

                // A body says the consequence; it never asks the title's
                // question again. Blade-expression titles/bodies are skipped
                // (the two $isOnlyLine ones are checked by hand).
                if (isset($m[1]) && ! str_contains($m[1], '{{')
                    && preg_match('/data-confirm="([^"]*)"/', $chunk, $b) && ! str_starts_with(trim($b[1]), '{{')) {
                    $title = mb_strtolower(trim($m[1]));
                    $body = mb_strtolower(trim($b[1]));
                    if (str_starts_with($body, $title) || str_starts_with($body, rtrim($title, '?'))) {
                        $echoes[] = $where.' ("'.trim($b[1]).'")';
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Every data-confirm needs a data-confirm-title naming the action:\n".implode("\n", $offenders));
        $this->assertSame([], $bareBodies, "A dialog body should say what happens, not \"Are you sure\":\n".implode("\n", $bareBodies));
        $this->assertSame([], $echoes, "A dialog body repeats its title — say the consequence only:\n".implode("\n", $echoes));
    }

    public function test_the_confirm_order_dialog_names_both_choices(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Shop', 'email' => 's@example.ie', 'address' => 'Main St', 'city' => 'Bray', 'postal_code' => 'A98', 'is_active' => true,
        ]);
        $order = \App\Models\Order::create([
            'customer_id' => $customer->id, 'order_date' => today(), 'status' => 'pending',
            'payment_status' => 'pending', 'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $this->actingAs(User::factory()->create())->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-confirm-title="Confirm this order?"', false)
            ->assertSee('data-confirm="It goes to the picking floor and stock can be allocated to it."', false)
            ->assertSee('data-confirm-cancel-label="Not yet"', false)
            ->assertSee('data-confirm-label="Confirm order"', false);
    }
}
