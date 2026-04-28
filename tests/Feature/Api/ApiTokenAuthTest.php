<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiTokenAuthTest extends TestCase
{
    private const VALID_TOKEN = 'test-office-api-token-abc123';

    private const PROBE_URI = '/api/_test/token-probe';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.office.api_token', self::VALID_TOKEN);

        Route::middleware('api.token')->get(self::PROBE_URI, fn () => response()->json(['ok' => true]));
    }

    public function test_rejects_request_without_authorization_header(): void
    {
        $this->getJson(self::PROBE_URI)
            ->assertStatus(401)
            ->assertJsonPath('error', 'Unauthorized');
    }

    public function test_rejects_request_with_non_bearer_scheme(): void
    {
        $this->getJson(self::PROBE_URI, ['Authorization' => 'Basic '.base64_encode('user:pass')])
            ->assertStatus(401);
    }

    public function test_rejects_request_with_wrong_token(): void
    {
        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer wrong-token'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Unauthorized');
    }

    public function test_rejects_request_with_token_differing_by_one_byte(): void
    {
        $tampered = substr(self::VALID_TOKEN, 0, -1).'x';

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.$tampered])
            ->assertStatus(401);
    }

    public function test_returns_500_when_token_not_configured(): void
    {
        Config::set('services.office.api_token', null);

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer anything'])
            ->assertStatus(500)
            ->assertJsonPath('error', 'API authentication not configured');
    }

    public function test_accepts_request_with_valid_token(): void
    {
        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.self::VALID_TOKEN])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_accepts_previous_token_during_rotation(): void
    {
        $previous = 'previous-token-xyz789';
        Config::set('services.office.api_token_previous', $previous);

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.$previous])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_rejects_token_that_matches_neither_current_nor_previous(): void
    {
        Config::set('services.office.api_token_previous', 'previous-token-xyz789');

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer totally-wrong'])
            ->assertStatus(401);
    }

    public function test_ignores_empty_previous_token(): void
    {
        // An empty-string previous must not accept a blank token.
        Config::set('services.office.api_token_previous', '');

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '])
            ->assertStatus(401);
    }

    public function test_empty_allowlist_allows_all(): void
    {
        Config::set('services.office.allowed_ips', '');

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.self::VALID_TOKEN])
            ->assertStatus(200);
    }

    public function test_allowlist_blocks_ip_not_on_list(): void
    {
        Config::set('services.office.allowed_ips', '203.0.113.4');

        // Test server uses 127.0.0.1; token is valid but IP is off-list.
        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.self::VALID_TOKEN])
            ->assertStatus(403);
    }

    public function test_allowlist_accepts_ip_within_cidr(): void
    {
        Config::set('services.office.allowed_ips', '127.0.0.0/24,203.0.113.4');

        $this->getJson(self::PROBE_URI, ['Authorization' => 'Bearer '.self::VALID_TOKEN])
            ->assertStatus(200);
    }
}
