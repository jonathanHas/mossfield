<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 404 and 403 render the app's own error page with one way back (F-41):
 * factory → picking, other signed-in users → dashboard, guests → login.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_404_links_to_login(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee("That page doesn't exist.")
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('/no-such-page')
            ->assertDontSee('Stack trace')
            ->assertDontSee('vendor/laravel');
    }

    public function test_an_office_404_links_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/orders/999999')
            ->assertNotFound()
            ->assertSee("That page doesn't exist.")
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('Back to the dashboard')
            ->assertSee('/orders/999999');
    }

    public function test_a_factory_403_links_back_to_picking(): void
    {
        $this->actingAs(User::factory()->factoryWorker()->create())->get('/customers')
            ->assertForbidden()
            ->assertSee("You don't have access to that page.")
            ->assertSee('href="'.route('picking.index').'"', false)
            ->assertSee('Back to picking')
            ->assertDontSee('Stack trace');
    }

    public function test_json_requests_still_get_json(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/orders/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_an_unmatched_url_still_knows_who_is_signed_in(): void
    {
        $this->actingAs(User::factory()->create())->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Back to the dashboard')
            ->assertSee('href="'.route('dashboard').'"', false);

        $this->actingAs(User::factory()->factoryWorker()->create())->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Back to picking');
    }

    public function test_an_unmatched_json_request_gets_json(): void
    {
        $this->getJson('/no-such-page')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
