<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Api;

use SkladX\PrestaShop\Config\Settings;
use SkladX\PrestaShop\Support\Logger;
use SkladX\PrestaShop\Support\T;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SkladX REST API v1 client (cURL). Successful calls return the decoded JSON
 * body (`{data, meta}`); anything else throws an ApiException.
 *
 * TLS is always verified. For local development against a SkladX with a
 * private CA (e.g. Laravel Herd `*.test`), define the constant
 * `SKLADX_CA_BUNDLE` (path to a PEM file) in config/defines_custom.inc.php.
 */
final class Client
{
    private const TIMEOUT = 20;

    /**
     * @param int $timeout total request timeout in seconds
     */
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey, private readonly int $timeout = self::TIMEOUT)
    {
    }

    /**
     * @param int|null $timeout shorter timeout for calls made while a request is still running
     */
    public static function fromSettings(?int $timeout = null): self
    {
        return new self(Settings::apiUrl(), Settings::apiKey(), $timeout ?? self::TIMEOUT);
    }

    /**
     * GET /ping — which client, environment and scopes the key works with.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return (array) ($this->request('GET', 'ping')['data'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createOrder(array $payload, string $idempotencyKey): array
    {
        return (array) ($this->request('POST', 'orders', $payload, [], $idempotencyKey)['data'] ?? []);
    }

    /**
     * @param string $id `ord_…` id or the order reference
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $id): array
    {
        return (array) ($this->request('GET', 'orders/' . rawurlencode($id))['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelOrder(string $id, ?string $reason = null): array
    {
        return (array) ($this->request('POST', 'orders/' . rawurlencode($id) . '/cancel', array_filter(['reason' => $reason]))['data'] ?? []);
    }

    /**
     * Create or update a product (matched by external_id, then by SKU).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upsertProduct(array $payload): array
    {
        return (array) ($this->request('POST', 'products', $payload)['data'] ?? []);
    }

    /**
     * GET /stock — one page of stock levels (`sku`, `on_hand`, `reserved`, `available`).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function stock(?string $cursor = null, int $limit = 100): array
    {
        return $this->page($this->request('GET', 'stock', null, array_filter(['limit' => $limit, 'cursor' => $cursor])));
    }

    /**
     * @param array<string, scalar> $query carrier, country, q, near, radius_km, cod, limit, cursor
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function pickupPoints(array $query): array
    {
        return $this->page($this->request('GET', 'pickup-points', null, $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function pickupPoint(string $carrier, string $id): array
    {
        return (array) ($this->request('GET', 'pickup-points/' . rawurlencode($carrier) . '/' . rawurlencode($id))['data'] ?? []);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function events(?string $after = null, int $limit = 100, ?string $cursor = null): array
    {
        return $this->page($this->request('GET', 'events', null, array_filter(['after' => $after, 'limit' => $limit, 'cursor' => $cursor])));
    }

    /**
     * @param list<string> $events
     *
     * @return array<string, mixed> includes the one-time `secret`
     */
    public function createWebhook(string $url, array $events, string $description): array
    {
        return (array) ($this->request('POST', 'webhooks', ['url' => $url, 'events' => $events, 'description' => $description])['data'] ?? []);
    }

    public function deleteWebhook(string $id): void
    {
        $this->request('DELETE', 'webhooks/' . rawurlencode($id));
    }

    /**
     * @param array<string, scalar> $query status, cursor, limit
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function inboundReceipts(array $query = []): array
    {
        return $this->page($this->request('GET', 'inbound-receipts', null, array_filter($query, static fn ($value): bool => (string) $value !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function inboundReceipt(string $id): array
    {
        return (array) ($this->request('GET', 'inbound-receipts/' . rawurlencode($id))['data'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload ReceiptPayload::build()
     *
     * @return array<string, mixed>
     */
    public function createInboundReceipt(array $payload, string $idempotencyKey): array
    {
        return (array) ($this->request('POST', 'inbound-receipts', $payload, [], $idempotencyKey)['data'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload ReceiptPayload::build()
     *
     * @return array<string, mixed>
     */
    public function updateInboundReceipt(string $id, array $payload): array
    {
        return (array) ($this->request('PUT', 'inbound-receipts/' . rawurlencode($id), $payload)['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function announceInboundReceipt(string $id): array
    {
        return (array) ($this->request('POST', 'inbound-receipts/' . rawurlencode($id) . '/announce', [])['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelInboundReceipt(string $id, ?string $reason = null): array
    {
        return (array) ($this->request('POST', 'inbound-receipts/' . rawurlencode($id) . '/cancel', array_filter(['reason' => $reason]))['data'] ?? []);
    }

    /**
     * POST /inbound-receipts/{id}/attachments (multipart/form-data, field `file`).
     *
     * @return array<string, mixed>
     */
    public function uploadInboundAttachment(string $id, string $filePath, string $filename, string $mimeType): array
    {
        $contents = is_readable($filePath) ? file_get_contents($filePath) : false;

        if ($contents === false) {
            throw new ApiException(T::__('The uploaded file cannot be read.'), 0, 'file_unreadable');
        }

        $boundary = 'skladx' . bin2hex(random_bytes(12));
        $filename = str_replace(['"', "\r", "\n"], '', $filename);
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
            . 'Content-Type: ' . $mimeType . "\r\n\r\n"
            . $contents . "\r\n"
            . '--' . $boundary . "--\r\n";

        $response = $this->send('POST', 'inbound-receipts/' . rawurlencode($id) . '/attachments', [], $body, 'multipart/form-data; boundary=' . $boundary);

        return (array) ($response['decoded']['data'] ?? []);
    }

    /**
     * GET /inbound-receipts/{id}/label — the label to stick on the parcel.
     *
     * @param string $format pdf|zpl
     *
     * @return array{body: string, content_type: string}
     */
    public function inboundReceiptLabel(string $id, string $format = 'pdf'): array
    {
        $response = $this->send('GET', 'inbound-receipts/' . rawurlencode($id) . '/label', ['format' => $format], null, null, null, $format === 'zpl' ? 'application/zpl, text/plain' : 'application/pdf');

        return [
            'body' => $response['body'],
            'content_type' => $response['content_type'],
        ];
    }

    /**
     * Sends one JSON request.
     *
     * @param array<string, mixed>|null $body JSON body
     * @param array<string, mixed> $query query string
     *
     * @return array<string, mixed>
     *
     * @throws ApiException on network errors and non-2xx responses
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null): array
    {
        $encoded = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->send($method, $path, $query, $encoded, $body === null ? null : 'application/json', $idempotencyKey, 'application/json', $body)['decoded'];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function page(array $response): array
    {
        return [
            'data' => array_values(array_filter((array) ($response['data'] ?? []), 'is_array')),
            'meta' => (array) ($response['meta'] ?? []),
        ];
    }

    /**
     * Sends one request with a raw body.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $logBody body for the debug log (raw bodies are not logged)
     *
     * @return array{status: int, body: string, content_type: string, decoded: array<string, mixed>}
     *
     * @throws ApiException
     */
    private function send(string $method, string $path, array $query, ?string $body, ?string $contentType, ?string $idempotencyKey = null, string $accept = 'application/json', ?array $logBody = null): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new ApiException(T::__('SkladX API URL or API key is not configured.'), 0, 'not_configured');
        }

        if (!function_exists('curl_init')) {
            throw new ApiException('The PHP cURL extension is required.', 0, 'no_curl');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query(array_map('strval', $query), '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: ' . $accept,
            'User-Agent: ' . sprintf('SkladX-PrestaShop/%s (PrestaShop %s; PHP %s)', Settings::MODULE_VERSION, _PS_VERSION_, PHP_VERSION),
        ];

        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: ' . (string) $contentType;
        }

        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $this->timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, intdiv($this->timeout, 2))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if (defined('SKLADX_CA_BUNDLE') && is_readable((string) constant('SKLADX_CA_BUNDLE'))) {
            curl_setopt($curl, CURLOPT_CAINFO, (string) constant('SKLADX_CA_BUNDLE'));
        }

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $started = microtime(true);
        $raw = curl_exec($curl);
        $duration = (int) round((microtime(true) - $started) * 1000);

        if ($raw === false) {
            $error = curl_error($curl);
            Logger::error(sprintf('SkladX %s %s failed: %s', $method, $path, $error));

            throw new ApiException($error !== '' ? $error : 'Network error');
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $raw = (string) $raw;
        $responseType = (string) ($responseHeaders['content-type'] ?? '');
        $decoded = (str_contains($responseType, 'json') || $responseType === '') ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        Logger::debug(
            sprintf('SkladX %s %s → %d (%d ms)', $method, $path, $status, $duration),
            [
                'request' => $logBody ?? ($body !== null ? sprintf('[%s, %d bytes]', (string) $contentType, strlen($body)) : null),
                'response' => $decoded !== [] ? $decoded : sprintf('[%s, %d bytes]', $responseType, strlen($raw)),
            ]
        );

        if ($status >= 200 && $status < 300) {
            return [
                'status' => $status,
                'body' => $raw,
                'content_type' => $responseType,
                'decoded' => $decoded,
            ];
        }

        if ($decoded === []) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        throw new ApiException((string) ($error['message'] ?? (is_string($decoded['message'] ?? null) ? $decoded['message'] : sprintf('HTTP %d', $status))), $status, (string) ($error['code'] ?? 'http_' . $status), is_array($error['details'] ?? null) ? $error['details'] : [], isset($error['request_id']) ? (string) $error['request_id'] : null);
    }
}
