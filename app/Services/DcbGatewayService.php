<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DcbGatewayService
{
    private const TOKEN_CACHE_KEY = 'dcb_gateway_access_token';

    public function isConfigured(): bool
    {
        $config = config('services.dcb');

        return !empty($config['business_id'])
            && !empty($config['api_key'])
            && !empty($config['api_secret']);
    }

    public function baseUrl(): string
    {
        return rtrim(config('services.dcb.base_url', 'https://gateway.smartsot.tz'), '/');
    }

    /**
     * @return array{success: bool, token?: string, expires_in?: int, message?: string, http_status?: int}
     */
    public function generateToken(): array
    {
        $secret = config('services.dcb.api_secret') ?: config('services.dcb.secret_key');

        $payload = [
            'business_id' => config('services.dcb.business_id'),
            'api_key' => config('services.dcb.api_key'),
            'api_secret' => $secret,
        ];

        $response = $this->httpClient()
            ->post($this->endpoint('/api/v1/generate-token'), $payload);

        $body = $response->json() ?? [];

        if ($response->successful() && ($body['success'] ?? false) && !empty($body['token'])) {
            $ttl = (int) ($body['expires_in'] ?? config('services.dcb.token_ttl', 300));
            $ttl = max(60, $ttl - 30);

            Cache::put(self::TOKEN_CACHE_KEY, $body['token'], $ttl);

            return [
                'success' => true,
                'token' => $body['token'],
                'expires_in' => $body['expires_in'] ?? null,
                'message' => $body['message'] ?? null,
                'http_status' => $response->status(),
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? $response->body(),
            'http_status' => $response->status(),
            'data' => $body,
        ];
    }

    public function clearToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    public function getToken(bool $forceRefresh = false): ?string
    {
        if ($forceRefresh) {
            $this->clearToken();
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $result = $this->generateToken();

        return $result['success'] ? ($result['token'] ?? null) : null;
    }

    /**
     * @return array{success: bool, financial_institutions?: array, fsps?: array, message?: string, http_status?: int}
     */
    public function getFinancialInstitutions(): array
    {
        return $this->authorizedRequest('GET', '/api/v1/get-financial-institutions');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function accountLookup(array $params): array
    {
        return $this->authorizedRequest('POST', '/api/v1/account-lookup', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function transfer(array $params): array
    {
        return $this->authorizedRequest('POST', '/api/v1/transfer', $params, 60);
    }

    /**
     * Loan repayment collection — USSD / push payment from customer wallet.
     *
     * @param  array<string, mixed>  $params  msisdn, amount, control_no?, bank_account_no?, client_reference?, request_id?
     * @return array<string, mixed>
     */
    public function collect(array $params): array
    {
        return $this->authorizedRequest('POST', '/api/v1/collect', $params, 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'DCB gateway credentials are not configured.',
            ];
        }

        $tokenResult = $this->generateToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $institutions = $this->getFinancialInstitutions();

        return [
            'success' => ($institutions['success'] ?? false),
            'message' => ($institutions['success'] ?? false)
                ? 'Connected successfully. Token issued and financial institutions retrieved.'
                : ($institutions['message'] ?? 'Failed to retrieve financial institutions.'),
            'institutions_count' => count($institutions['financial_institutions'] ?? $institutions['fsps'] ?? []),
            'http_status' => $institutions['http_status'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizedRequest(string $method, string $path, ?array $payload = null, int $timeout = 30): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'DCB gateway is not configured.',
                'http_status' => 503,
            ];
        }

        $attempt = 0;
        $maxAttempts = 2;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $token = $this->getToken($attempt > 1);

            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Unable to obtain DCB access token.',
                    'http_status' => 401,
                ];
            }

            try {
                $client = $this->httpClient($token)->timeout($timeout);
                $url = $this->endpoint($path);

                $response = match (strtoupper($method)) {
                    'GET' => $client->get($url),
                    'POST' => $client->post($url, $payload ?? []),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
                };

                if (in_array($response->status(), [401, 403], true) && $attempt < $maxAttempts) {
                    $this->clearToken();
                    continue;
                }

                $body = $response->json() ?? [];

                return array_merge($body, [
                    'http_status' => $response->status(),
                ]);
            } catch (RequestException $e) {
                Log::error('DCB gateway request failed', [
                    'path' => $path,
                    'message' => $e->getMessage(),
                    'status' => $e->response?->status(),
                ]);

                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'http_status' => $e->response?->status() ?? 502,
                    'data' => $e->response?->json(),
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'DCB authorization failed after retry.',
            'http_status' => 401,
        ];
    }

    private function httpClient(?string $bearerToken = null)
    {
        $client = Http::acceptJson()
            ->asJson()
            ->timeout(config('services.dcb.timeout', 30));

        if ($bearerToken) {
            $client = $client->withToken($bearerToken);
        }

        return $client;
    }

    private function endpoint(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }
}
