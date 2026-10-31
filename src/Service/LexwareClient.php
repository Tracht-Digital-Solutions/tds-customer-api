<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin client for the Lexware Office (formerly lexoffice) public REST
 * API. Only the bit the time tracker needs: create an invoice from
 * aggregated time. Auth is the API key as a Bearer token (created in
 * the Lexware Office settings); the base URL defaults to the current
 * production host and is overridable for the sandbox.
 *
 * @see https://developers.lexware.io/docs/  (Invoices endpoint)
 */
final class LexwareClient
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        /** e.g. https://api.lexware.io/v1 (sandbox: https://api.lexware-sandbox.de/v1). */
        private readonly string $baseUrl = 'https://api.lexware.io/v1',
    ) {
    }

    /** False when no API key is configured — the feature is then disabled. */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Create an invoice. With $finalize the invoice is created in `open`
     * status (a real, numbered invoice); otherwise it stays a `draft`.
     *
     * @param array<string,mixed> $payload the Lexware invoice JSON body
     * @return array<string,mixed> decoded response (id, resourceUri, …)
     * @throws LexwareException on a non-201 response or transport error
     */
    public function createInvoice(array $payload, bool $finalize = false): array
    {
        $url = $this->baseUrl . '/invoices' . ($finalize ? '?finalize=true' : '');

        try {
            $res = $this->http->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 20,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new LexwareException('Lexware nicht erreichbar: ' . $e->getMessage(), 0);
        }

        $status = $res->getStatusCode();
        $bodyRaw = (string) $res->getBody();
        $body = json_decode($bodyRaw, true);

        // The Invoices endpoint returns 201 Created on success.
        if ($status !== 201) {
            throw new LexwareException(self::errorMessage($status, is_array($body) ? $body : null), $status);
        }
        if (!is_array($body) || !isset($body['id'])) {
            throw new LexwareException('Unerwartete Lexware-Antwort (keine Rechnungs-ID).', $status);
        }
        return $body;
    }

    /** Build a readable message from a Lexware error envelope. */
    private static function errorMessage(int $status, ?array $body): string
    {
        $detail = '';
        if ($body !== null) {
            if (isset($body['message']) && is_string($body['message'])) {
                $detail = $body['message'];
            } elseif (isset($body['IssueList'][0]['i18nKey']) && is_string($body['IssueList'][0]['i18nKey'])) {
                $detail = $body['IssueList'][0]['i18nKey'];
            }
        }
        $hint = match (true) {
            $status === 401 => 'API-Key ungültig oder abgelaufen',
            $status === 402 => 'Lexware-Abo deckt die API nicht ab',
            $status === 403 => 'API-Key ohne Berechtigung',
            $status === 406 => 'Rechnungsdaten von Lexware abgelehnt',
            default => 'HTTP ' . $status,
        };
        return $detail !== '' ? "{$hint}: {$detail}" : $hint;
    }
}
