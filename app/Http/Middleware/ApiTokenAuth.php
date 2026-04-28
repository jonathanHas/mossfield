<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    /**
     * Handle an incoming request.
     *
     * Validates the API token from the Authorization header.
     * Expected format: Authorization: Bearer {token}
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->ipIsAllowed($request)) {
            Log::warning('api_token_auth: ip not allowed', $this->context($request));

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Caller IP is not on the allowlist.',
            ], 403);
        }

        $currentToken = config('services.office.api_token');
        $previousToken = config('services.office.api_token_previous');

        // If no current token is configured, reject all requests
        if (empty($currentToken)) {
            Log::error('api_token_auth: no token configured', $this->context($request));

            return response()->json([
                'error' => 'API authentication not configured',
            ], 500);
        }

        // Extract token from Authorization header
        $authHeader = $request->header('Authorization');

        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('api_token_auth: missing or malformed header', $this->context($request));

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header. Expected format: Bearer {token}',
            ], 401);
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        $version = $this->matchToken($providedToken, $currentToken, $previousToken);

        if ($version === null) {
            Log::warning('api_token_auth: invalid token', $this->context($request) + [
                'provided_fingerprint' => $this->fingerprint($providedToken),
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API token',
            ], 401);
        }

        Log::info('api_token_auth: accepted', $this->context($request) + [
            'token_fingerprint' => $this->fingerprint($providedToken),
            'token_version' => $version,
        ]);

        return $next($request);
    }

    /**
     * Match a provided token against the current and previous tokens.
     * Returns 'current', 'previous', or null if neither matches.
     * Both comparisons use hash_equals to stay timing-safe.
     */
    private function matchToken(string $provided, string $current, ?string $previous): ?string
    {
        if (hash_equals($current, $provided)) {
            return 'current';
        }

        if (! empty($previous) && hash_equals($previous, $provided)) {
            return 'previous';
        }

        return null;
    }

    private function context(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'uri' => $request->getRequestUri(),
        ];
    }

    private function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 8);
    }

    private function ipIsAllowed(Request $request): bool
    {
        $raw = (string) config('services.office.allowed_ips', '');

        if ($raw === '') {
            return true; // No allowlist configured — allow all.
        }

        $allowed = array_filter(array_map('trim', explode(',', $raw)));

        if ($allowed === []) {
            return true;
        }

        return IpUtils::checkIp((string) $request->ip(), $allowed);
    }
}
