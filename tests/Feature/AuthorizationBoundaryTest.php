<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseConversionLog;
use App\Models\CustomerSpecialPrice;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\PaymentService;
use App\Services\StatementService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * The authorisation boundary, route by route.
 *
 * Every other suite checks the roles it cares about on the routes it owns. This
 * one walks the router so a new route can't ship outside the fence: it must sit
 * behind `auth` (or be a guest page), it must carry a `role:` group unless it is
 * one of the account pages, and each role gets exactly what the group says —
 * with the factory write carve-outs (OrderPolicy::fulfill / ::load) named
 * explicitly so anything else factory can POST to in the shared group fails here.
 *
 * Route parameters are filled from real rows (`$this->params`), because the
 * `role` middleware runs after route-model binding: a made-up id would 404
 * before the role check and prove nothing. A route whose parameter has no
 * fixture fails loudly rather than being skipped.
 *
 * See docs/planImp/TESTING_AND_FIXES.md → 2026-10-04 authorisation pass.
 */
class AuthorizationBoundaryTest extends InvoicingTestCase
{
    /** Reachable with a session but no role: a user's own account. */
    private const ACCOUNT_ROUTES = [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy',
        'logout', 'password.update', 'password.confirm',
    ];

    /**
     * `/` redirects by auth state; `up` is the health check; the fallback
     * (`Route::fallback`, last in web.php) only ever aborts 404. None is a page.
     */
    private const UNGUARDED_URIS = ['/', 'up', '{fallbackPlaceholder}'];

    /** Shared-group writes factory may perform — the only two policy carve-outs. */
    private const FACTORY_WRITE_CARVE_OUTS = [
        'picking.pick', 'picking.undo', 'picking.short', 'picking.reopen',
        'chilled-runs.toggle-loaded',
    ];

    /** @var array<string, string|int> route parameter name => real id */
    private array $params = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ---------------------------------------------------------------- structure

    public function test_every_web_route_is_behind_auth_or_is_a_guest_page(): void
    {
        foreach ($this->webRoutes() as $route) {
            if (in_array($route->uri(), self::UNGUARDED_URIS, true)) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $this->assertTrue(
                in_array('auth', $mw, true) || in_array('guest', $mw, true),
                "{$this->label($route)} has neither auth nor guest middleware"
            );
        }
    }

    public function test_every_authenticated_route_outside_the_account_pages_has_a_role_group(): void
    {
        foreach ($this->authRoutes() as $route) {
            // Breeze leaves POST /confirm-password unnamed; it is the account page's own form.
            if (in_array($route->getName(), self::ACCOUNT_ROUTES, true) || $route->uri() === 'confirm-password') {
                continue;
            }
            $this->assertNotNull(
                $this->roleMiddleware($route),
                "{$this->label($route)} is authenticated but has no role: middleware"
            );
        }
    }

    public function test_every_bound_route_parameter_has_a_fixture(): void
    {
        foreach ($this->authRoutes() as $route) {
            foreach ($route->parameterNames() as $name) {
                $this->assertArrayHasKey(
                    $name, $this->params,
                    "No fixture for {{$name}} on {$this->label($route)} — add one to buildFixtures()"
                );
            }
        }
    }

    // -------------------------------------------------------------- logged out

    public function test_guests_are_sent_to_login_from_every_authenticated_route(): void
    {
        foreach ($this->authRoutes() as $route) {
            $this->call($this->method($route), $this->url($route))
                ->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_the_api_refuses_a_request_without_a_bearer_token(): void
    {
        config(['services.office.api_token' => 'configured-token']);

        $this->getJson('/api/products')->assertUnauthorized();
        $this->getJson('/api/products', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    // ------------------------------------------------------------------ driver

    public function test_driver_is_denied_every_role_gated_route(): void
    {
        $driver = User::factory()->driver()->create();

        foreach ($this->roleRoutes() as $route) {
            $this->actingAs($driver)
                ->call($this->method($route), $this->url($route))
                ->assertForbidden();
        }
    }

    public function test_driver_keeps_the_account_pages(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get(route('dashboard'))->assertOk()
            ->assertSee('route manifest')
            ->assertDontSee('€');
        $this->actingAs($driver)->get(route('profile.edit'))->assertOk();
    }

    // ----------------------------------------------------------------- factory

    public function test_factory_is_denied_every_office_and_admin_route(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        foreach ($this->roleRoutes() as $route) {
            if (in_array('factory', $this->roles($route), true)) {
                continue;
            }
            $this->actingAs($factory)
                ->call($this->method($route), $this->url($route))
                ->assertForbidden();
        }
    }

    public function test_factory_cannot_write_in_the_shared_group_except_through_the_carve_outs(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $checked = 0;

        foreach ($this->roleRoutes() as $route) {
            if (! in_array('factory', $this->roles($route), true)) {
                continue;
            }
            if (in_array($route->getName(), self::FACTORY_WRITE_CARVE_OUTS, true)) {
                continue;
            }
            $isWrite = $this->method($route) !== 'GET'
                || Str::endsWith($route->getName(), ['.create', '.edit']);
            if (! $isWrite) {
                continue;
            }

            $checked++;
            $this->actingAs($factory)
                ->call($this->method($route), $this->url($route))
                ->assertForbidden();
        }

        // Sanity: the loop found the shared-group writes it exists to check.
        $this->assertGreaterThanOrEqual(15, $checked);
    }

    public function test_factory_can_read_every_shared_route(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        foreach ($this->roleRoutes() as $route) {
            if (! in_array('factory', $this->roles($route), true) || $this->method($route) !== 'GET') {
                continue;
            }
            if (Str::endsWith($route->getName(), ['.create', '.edit'])) {
                continue;
            }
            $this->assertAllowed($factory, $route);
        }
    }

    public function test_factory_carve_outs_still_work(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->post(route('chilled-runs.toggle-loaded', $this->params['order']))
            ->assertRedirect();
        $this->assertNotNull(Order::find($this->params['order'])->loaded_at);
    }

    // ------------------------------------------------------------------ office

    public function test_office_is_denied_every_admin_only_route(): void
    {
        $office = User::factory()->create();

        foreach ($this->roleRoutes() as $route) {
            if ($this->roles($route) !== ['admin']) {
                continue;
            }
            $this->actingAs($office)
                ->call($this->method($route), $this->url($route))
                ->assertForbidden();
        }
    }

    public function test_office_can_read_every_non_admin_route(): void
    {
        $office = User::factory()->create();

        foreach ($this->roleRoutes() as $route) {
            if ($this->roles($route) === ['admin'] || $this->method($route) !== 'GET') {
                continue;
            }
            $this->assertAllowed($office, $route);
        }
    }

    public function test_admin_can_read_every_route(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ($this->roleRoutes() as $route) {
            if ($this->method($route) !== 'GET') {
                continue;
            }
            $this->assertAllowed($admin, $route);
        }
    }

    // --------------------------------------------------------- deactivated users

    public function test_a_deactivated_user_is_logged_out_on_their_next_request(): void
    {
        $user = User::factory()->factoryWorker()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('stock.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_use_the_account_pages_either(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Ghost'])
            ->assertRedirect(route('login'));
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_deactivating_a_user_invalidates_their_remember_me_cookie(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->factoryWorker()->create(['username' => 'packer']);

        // Sign in with "Remember me" and keep the recaller cookie.
        $login = $this->post('/login', ['login' => 'packer', 'password' => 'password', 'remember' => 'on']);
        $login->assertRedirect(route('picking.index'));
        $recaller = $login->getCookie($this->recallerName());
        $this->assertNotNull($recaller, 'login did not set a remember cookie');

        // Prove the cookie alone signs them in on a cold browser, so the
        // assertion below is not passing vacuously.
        $this->freshBrowser();
        $this->withCookie($recaller->getName(), $recaller->getValue())
            ->get(route('stock.index'))
            ->assertOk();
        $this->assertAuthenticatedAs($worker);

        // The office deactivates them. Their database sessions are deleted…
        $this->freshBrowser();
        $this->actingAs($admin)->post(route('users.deactivate', $worker))->assertRedirect();

        // …and the cookie alone must not sign them back in.
        $this->freshBrowser();
        $this->withCookie($recaller->getName(), $recaller->getValue())
            ->get(route('stock.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivation_and_role_change_cycle_the_remember_token(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->factoryWorker()->create(['username' => 'packer2']); // alpha_dash-safe
        $before = $worker->remember_token;

        $this->actingAs($admin)->post(route('users.deactivate', $worker));
        $afterDeactivate = $worker->fresh()->remember_token;
        $this->assertNotSame($before, $afterDeactivate);

        $this->actingAs($admin)->post(route('users.reactivate', $worker));
        $this->actingAs($admin)->put(route('users.update', $worker), [
            'name' => $worker->name, 'username' => $worker->username,
            'role' => 'office', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNotSame($afterDeactivate, $worker->fresh()->remember_token);
    }

    public function test_a_role_change_takes_effect_on_the_users_next_request(): void
    {
        $user = User::factory()->create(); // office

        $this->actingAs($user)->get(route('customers.index'))->assertOk();

        $user->forceFill(['role' => 'factory'])->save();

        $this->get(route('customers.index'))->assertForbidden();
    }

    // ----------------------------------------------------------- ownership guards

    public function test_an_order_line_cannot_be_reached_through_another_order(): void
    {
        $office = User::factory()->create();
        $other = $this->deliveredOrder(1, 'confirmed');

        $this->actingAs($office)
            ->patch(route('orders.items.update', [$other, $this->params['orderItem']]), ['quantity_ordered' => 1])
            ->assertNotFound();
        $this->actingAs($office)
            ->get(route('picking.item', [$other, $this->params['orderItem']]))
            ->assertNotFound();
    }

    public function test_a_special_price_cannot_be_reached_through_another_customer(): void
    {
        $office = User::factory()->create();
        $other = \App\Models\Customer::create([
            'name' => 'Other Shop', 'email' => 'o@example.ie',
            'address' => '2 High St', 'city' => 'Cork', 'postal_code' => 'T12 AB12',
            'is_active' => true,
        ]);

        $this->actingAs($office)
            ->delete(route('customers.special-prices.destroy', [$other, $this->params['special_price']]))
            ->assertNotFound();
        $this->assertDatabaseHas('customer_special_prices', ['id' => $this->params['special_price']]);
    }

    // ------------------------------------------------------------------ helpers

    private function buildFixtures(): void
    {
        $batchItem = $this->batchItemFor($this->wheel, 10);
        $batch = $batchItem->batch;

        $order = $this->deliveredOrder(2, 'confirmed');
        $item = $order->orderItems->first();
        $item->update(['quantity_fulfilled' => 0]);
        $allocation = OrderAllocation::create([
            'order_item_id' => $item->id,
            'batch_item_id' => $this->batchItemFor($this->bottle)->id,
            'quantity_allocated' => 2,
            'quantity_fulfilled' => 0,
            'allocated_at' => now(),
        ]);
        $item->update(['quantity_allocated' => 2]);

        $special = CustomerSpecialPrice::create([
            'customer_id' => $this->shop->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.20,
        ]);

        $run = DeliveryRun::create(['name' => 'Dublin', 'day_of_week' => 2, 'is_active' => true]);
        $this->shop->update(['delivery_run_id' => $run->id, 'run_position' => 1]);

        $matureBatch = Batch::create([
            'product_id' => $this->wheel->product_id,
            'batch_code' => 'MAT-1',
            'production_date' => today()->subMonths(6),
            'raw_milk_litres' => 100,
            'status' => 'active',
            'source_batch_id' => $batch->id,
        ]);
        $matureItem = BatchItem::create([
            'batch_id' => $matureBatch->id,
            'product_variant_id' => $this->wheel->id,
            'quantity_produced' => 1,
            'quantity_remaining' => 1,
        ]);
        $log = CheeseConversionLog::create([
            'source_batch_item_id' => $batchItem->id,
            'target_batch_item_id' => $matureItem->id,
            'conversion_date' => today(),
            'wheels_converted' => 1,
            'total_weight_kg' => 4.0,
        ]);

        $invoice = $this->issue($this->deliveredOrder());
        $payment = app(PaymentService::class)->record($this->shop, [
            'amount' => 5.00, 'received_at' => today()->toDateString(),
            'method' => 'bank_transfer', 'reference' => 'TRF-1',
        ], []);
        $creditNote = app(CreditNoteService::class)->issue($invoice, [
            ['description' => 'Returned goods', 'quantity' => 1, 'amount' => 1.00, 'vat_rate' => 0],
        ], 'Returned');
        $statement = app(StatementService::class)->generate(
            $this->shop, today()->startOfMonth(), today()->endOfMonth()
        );
        $statementBatch = (string) Str::uuid();
        $statement->update(['batch_id' => $statementBatch]);

        $otherUser = User::factory()->create();

        // Stock write-offs (cycle 7): a recorded write-off, and a reason.
        $writeOffReason = \App\Models\WriteOffReason::where('code', 'spoiled')->firstOrFail();
        $writeOff = \App\Models\StockWriteOff::create([
            'batch_item_id' => $batchItem->id, 'write_off_reason_id' => $writeOffReason->id,
            'quantity' => 1, 'written_off_on' => today(),
        ]);

        $this->params = [
            'batch' => $batch->id,            // batches.* — statements.batch.* is overridden in url()
            'batchItem' => $batchItem->id,
            'log' => $log->id,
            'order' => $order->id,
            'orderItem' => $item->id,
            'allocation' => $allocation->id,
            'customer' => $this->shop->id,
            'special_price' => $special->id,
            'deliveryRun' => $run->id,
            'delivery_run' => $run->id,
            'product' => $this->bottle->product_id,
            'variant' => $this->bottle->id,
            'invoice' => $invoice->id,
            'credit_note' => $creditNote->id,
            'payment' => $payment->id,
            'statement' => $statement->id,
            'statement_batch' => $statementBatch,
            'user' => $otherUser->id,
            'writeOff' => $writeOff->id,
            'write_off_reason' => $writeOffReason->id,
        ];
    }

    /** @return array<int, Route> */
    private function webRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $r) => in_array('web', $r->gatherMiddleware(), true)
                && ! Str::startsWith($r->uri(), ['_', 'sanctum', 'storage'])
        ));
    }

    /** @return array<int, Route> */
    private function authRoutes(): array
    {
        return array_values(array_filter(
            $this->webRoutes(),
            fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true)
        ));
    }

    /** @return array<int, Route> */
    private function roleRoutes(): array
    {
        return array_values(array_filter(
            $this->authRoutes(),
            fn (Route $r) => $this->roleMiddleware($r) !== null
        ));
    }

    private function roleMiddleware(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $mw) {
            if (Str::startsWith($mw, 'role:')) {
                return $mw;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function roles(Route $route): array
    {
        $roles = explode(',', Str::after((string) $this->roleMiddleware($route), 'role:'));
        sort($roles);

        return $roles;
    }

    private function method(Route $route): string
    {
        $methods = array_diff($route->methods(), ['HEAD']);

        return in_array('GET', $methods, true) ? 'GET' : reset($methods);
    }

    private function url(Route $route): string
    {
        $params = $this->params;
        if (Str::startsWith((string) $route->getName(), 'invoicing.statements.batch.')) {
            $params['batch'] = $this->params['statement_batch'];
        }

        return '/'.ltrim(preg_replace_callback(
            '/\{(\w+)\??\}/',
            fn ($m) => (string) $params[$m[1]],
            $route->uri()
        ), '/');
    }

    private function label(Route $route): string
    {
        return $this->method($route).' /'.$route->uri().' ('.($route->getName() ?? 'unnamed').')';
    }

    /**
     * "Allowed" is the absence of a refusal: not 401/403, not bounced to login,
     * and not a server error. Some GETs legitimately redirect (the retired
     * allocation page) or render a PDF, so a plain assertOk() is too narrow.
     */
    private function assertAllowed(User $user, Route $route): void
    {
        $response = $this->actingAs($user)->call('GET', $this->url($route));
        $status = $response->getStatusCode();

        $this->assertNotContains($status, [401, 403], "{$user->role->value} was refused {$this->label($route)}");
        $this->assertLessThan(500, $status, "{$this->label($route)} errored for {$user->role->value}");
        if ($response->isRedirect()) {
            $this->assertNotSame(route('login'), $response->headers->get('Location'), "{$this->label($route)} bounced {$user->role->value} to login");
        }
    }

    /** Forget the guard's cached user and the in-memory session: the next request is a cold one. */
    private function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->flushSession();
    }

    private function recallerName(): string
    {
        return $this->app['auth']->guard('web')->getRecallerName();
    }
}
