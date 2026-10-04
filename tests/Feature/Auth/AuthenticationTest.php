<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_authenticate_with_their_username(): void
    {
        User::factory()->create(['username' => 'office_a']);

        $response = $this->post('/login', [
            'login' => 'office_a',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('login');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_the_login_page_carries_the_mossfield_mark_not_the_laravel_logo(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('Mossfield', $html);
        $this->assertStringNotContainsString('M305.8 81.125', $html); // the Laravel logo's path
        $this->assertMatchesRegularExpression('/id="login"[^>]*autofocus/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*autofocus/', $html);
    }

    public function test_after_a_failed_attempt_the_password_box_takes_the_focus(): void
    {
        $user = User::factory()->create();

        $html = $this->from('/login')->followingRedirects()->post('/login', [
            'login' => $user->username,
            'password' => 'wrong-password',
        ])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="password"[^>]*autofocus/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="login"[^>]*autofocus/', $html);
        $this->assertStringContainsString('value="'.$user->username.'"', $html);
    }
}
