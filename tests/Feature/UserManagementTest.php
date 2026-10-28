<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const SELF_MESSAGE = "You can't change your own role or deactivate yourself — ask another admin.";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Ann Admin', 'username' => 'ann']);
    }

    /** The fields the edit form posts for $user, with overrides. */
    private function formFor(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'password' => '',
            'role' => $user->role->value,
            'is_active' => $user->is_active ? 1 : 0,
        ], $overrides);
    }

    public function test_an_admin_cannot_demote_themselves(): void
    {
        User::factory()->admin()->create(['username' => 'bob']); // not the last admin

        $this->actingAs($this->admin)
            ->from(route('users.edit', $this->admin))
            ->patch(route('users.update', $this->admin), $this->formFor($this->admin, ['role' => 'office']))
            ->assertRedirect(route('users.edit', $this->admin))
            ->assertSessionHasErrors(['role' => self::SELF_MESSAGE]);

        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
        $this->get(route('users.index'))->assertOk();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        User::factory()->admin()->create(['username' => 'bob']);

        $this->actingAs($this->admin)
            ->from(route('users.edit', $this->admin))
            ->patch(route('users.update', $this->admin), $this->formFor($this->admin, ['is_active' => 0]))
            ->assertRedirect(route('users.edit', $this->admin))
            ->assertSessionHasErrors(['is_active' => self::SELF_MESSAGE]);

        $this->assertTrue($this->admin->fresh()->is_active);
        $this->get(route('users.index'))->assertOk();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_demoting_another_admin_signs_them_out_everywhere(): void
    {
        $other = User::factory()->admin()->create(['name' => 'Bob Admin', 'username' => 'bob']);
        DB::table('sessions')->insert([
            'id' => 'bob-session', 'user_id' => $other->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('users.update', $other), $this->formFor($other, ['role' => 'office']))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'User updated — Bob Admin has been signed out everywhere.');

        $this->assertSame(UserRole::Office, $other->fresh()->role);
        $this->assertDatabaseMissing('sessions', ['id' => 'bob-session']);
    }

    public function test_saving_your_own_record_with_the_rendered_values_works(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->admin), $this->formFor($this->admin, ['name' => 'Ann A.']))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'User updated.');

        $this->assertSame('Ann A.', $this->admin->fresh()->name);
    }

    public function test_a_new_user_defaults_to_office(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('value="office" selected', false)
            ->assertDontSee('value="admin" selected', false);
    }

    public function test_your_own_edit_page_disables_role_and_active(): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.edit', $this->admin))->assertOk();

        $response->assertSee("You can't change your own role — ask another admin.", false);
        $response->assertSee("You can't change your own active flag — ask another admin.", false);
        $response->assertSee('<input type="hidden" name="role" value="admin">', false);
        $this->assertMatchesRegularExpression('/<select name="role"[^>]*disabled/', $response->getContent());

        // Someone else's page is untouched.
        $other = User::factory()->create(['username' => 'olive']);
        $this->get(route('users.edit', $other))
            ->assertDontSee("You can't change your own role", false)
            ->assertDontSee('<select name="role" id="role" required class="mf-select" disabled', false);
    }

    public function test_the_last_admin_demoting_themselves_gets_the_self_message(): void
    {
        $this->assertTrue($this->admin->isLastActiveAdmin());

        $this->actingAs($this->admin)
            ->patch(route('users.update', $this->admin), $this->formFor($this->admin, ['role' => 'office']))
            ->assertSessionHasErrors(['role' => self::SELF_MESSAGE]);

        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
    }
}
