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
}
