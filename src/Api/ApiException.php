<?php

/**
 * SkladX for PrestaShop
 *
 * @author    SkladX <info@skladx.sk>
 * @copyright SkladX
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

namespace SkladX\PrestaShop\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wraps the API error shape `{"error": {"code", "message", "request_id", "details"}}`.
 * Status 0 means the request never got a response (DNS, TLS, timeout…).
 */
class ApiException extends \RuntimeException
{
    /**
     * @param string $message human readable message (from the API when available)
     * @param int $status HTTP status, 0 for network errors
     * @param string $errorCode API error code, e.g. `validation_failed`
     * @param array<string, mixed> $details API error details
     * @param string|null $requestId API request id for support
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'network_error',
        public readonly array $details = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Network errors, rate limiting and server errors are worth retrying.
     */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * Field validation errors from a 422 `validation_failed` response.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        $fields = $this->details['fields'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        return array_map(static fn ($messages): array => array_values(array_map('strval', (array) $messages)), $fields);
    }

    /**
     * One-line summary including field errors, for notes and notices.
     */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->fieldErrors() as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }

        $text = $this->getMessage() . ($parts !== [] ? ' (' . implode('; ', $parts) . ')' : '');

        return $this->status > 0 ? sprintf('[%d %s] %s', $this->status, $this->errorCode, $text) : $text;
    }
}
