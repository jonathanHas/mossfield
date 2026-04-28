<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for fetching orders from the Mossorders online portal.
 *
 * This is a READ-ONLY service that retrieves order data from the external
 * Mossorders API without modifying any local database records.
 */
class OnlineOrderImportService
{
    /**
     * Fetch orders from the Mossorders API.
     *
     * @param  string|null  $since  Optional ISO8601 datetime to filter orders updated since this timestamp
     * @return array The orders data array, or empty array if request fails
     */
    public function fetchOrders(?string $since = null): array
    {
        $baseUrl = config('services.mossorders.base_url');
        $apiToken = config('services.mossorders.api_token');

        // Validate configuration
        if (! $baseUrl || ! $apiToken) {
            Log::error('Mossorders API configuration missing', [
                'base_url' => $baseUrl ? 'set' : 'missing',
                'api_token' => $apiToken ? 'set' : 'missing',
            ]);

            return [];
        }

        // Build the API endpoint URL
        $url = rtrim($baseUrl, '/').'/api/orders';

        try {
            // Prepare the HTTP request
            $request = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiToken,
                'Accept' => 'application/json',
            ])
                ->withOptions(['verify' => true])
                ->connectTimeout(5)
                ->timeout(10)
                ->retry(3, 500, throw: false);

            // Add query parameter if 'since' is provided
            if ($since !== null) {
                $request = $request->withQueryParameters(['since' => $since]);
            }

            // Make the API call
            $response = $request->get($url);

            // Check if request was successful
            if (! $response->successful()) {
                Log::error('Mossorders API request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            // Parse the JSON response
            $data = $response->json();

            // If response is not valid JSON, log warning and return empty
            if (! is_array($data)) {
                Log::warning('Mossorders API returned non-array response', [
                    'url' => $url,
                    'response_type' => gettype($data),
                ]);

                return [];
            }

            // If response has a 'data' key, return that array
            // Otherwise, return the entire response (flexible format)
            if (isset($data['data']) && is_array($data['data'])) {
                Log::info('Mossorders API fetch successful', [
                    'url' => $url,
                    'order_count' => count($data['data']),
                ]);

                return $data['data'];
            }

            // Return the whole response if no 'data' key
            Log::info('Mossorders API fetch successful (no data wrapper)', [
                'url' => $url,
                'item_count' => count($data),
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Mossorders API request exception', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }
}
