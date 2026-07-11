<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use PDO;

/**
 * Runtime settings store (the app_setting key/value table) for the
 * non-installation-relevant third-party config the admin edits in tds-admin:
 * Stripe, the SMTP ticket mailer, the IMAP inbox that feeds email→ticket
 * ingestion, and Lexware. Mirrors the ticket_setting / TicketSettings pattern,
 * generalised for string values with a typed key registry (setting_key == the
 * env var name, 1:1).
 *
 * Read precedence: a non-empty DB value wins, else the matching env var, else
 * the coded default. So a fresh install with no DB rows keeps running off .env
 * and the admin panel can override any key at runtime without a redeploy. The
 * env read uses the safe `?? false` precedence — never `$_ENV[$k] ?? getenv($k)
 * ?: $default` (that clobbers a legitimately-falsy value; see CLAUDE.md).
 *
 * Secret values (API keys / webhook secrets) are encrypted at rest with
 * AES-256-GCM under SETTINGS_ENCRYPTION_KEY. When that key is unset (local dev)
 * the store degrades to plaintext and reports encryptionAvailable=false.
 * publicState() never returns a raw secret — only configured/last4/source.
 */
final class AppSettings
{
    /**
     * key => [section, secret?, default]. `section` groups the admin UI;
     * `secret` marks values that get encrypted + masked.
     *
     * @var array<string, array{section: string, secret: bool, default: string}>
     */
    private const REGISTRY = [
        'STRIPE_SECRET_KEY'           => ['section' => 'stripe',      'secret' => true,  'default' => ''],
        'STRIPE_WEBHOOK_SECRET'       => ['section' => 'stripe',      'secret' => true,  'default' => ''],
        'STRIPE_PUBLIC_KEY'           => ['section' => 'stripe',      'secret' => false, 'default' => ''],
        'STRIPE_RETURN_URL'           => ['section' => 'stripe',      'secret' => false, 'default' => 'https://app.tracht-digital.de/invoices'],
        'SMTP_HOST'                   => ['section' => 'ticket_mail', 'secret' => false, 'default' => ''],
        'SMTP_PORT'                   => ['section' => 'ticket_mail', 'secret' => false, 'default' => '587'],
        'SMTP_USER'                   => ['section' => 'ticket_mail', 'secret' => false, 'default' => ''],
        'SMTP_PASSWORD'               => ['section' => 'ticket_mail', 'secret' => true,  'default' => ''],
        'SMTP_SECURITY'               => ['section' => 'ticket_mail', 'secret' => false, 'default' => 'tls'],
        'SMTP_FROM'                   => ['section' => 'ticket_mail', 'secret' => false, 'default' => 'Tracht Digital Solutions <noreply@tracht-digital.de>'],
        'TICKET_ADMIN_EMAIL'          => ['section' => 'ticket_mail', 'secret' => false, 'default' => ''],
        'TICKET_INBOX_ADDRESS'        => ['section' => 'ticket_mail', 'secret' => false, 'default' => ''],
        'IMAP_HOST'                   => ['section' => 'imap',        'secret' => false, 'default' => ''],
        'IMAP_PORT'                   => ['section' => 'imap',        'secret' => false, 'default' => '993'],
        'IMAP_USER'                   => ['section' => 'imap',        'secret' => false, 'default' => ''],
        'IMAP_PASSWORD'               => ['section' => 'imap',        'secret' => true,  'default' => ''],
        'IMAP_SECURITY'               => ['section' => 'imap',        'secret' => false, 'default' => 'ssl'],
        'IMAP_FOLDER'                 => ['section' => 'imap',        'secret' => false, 'default' => 'INBOX'],
        'INGEST_TOKEN'                => ['section' => 'imap',        'secret' => true,  'default' => ''],
        'LEXWARE_API_KEY'             => ['section' => 'lexware',     'secret' => true,  'default' => ''],
        'LEXWARE_API_URL'             => ['section' => 'lexware',     'secret' => false, 'default' => 'https://api.lexware.io/v1'],
        'LEXWARE_DEFAULT_HOURLY_RATE' => ['section' => 'lexware',     'secret' => false, 'default' => '0'],
        'LEXWARE_TAX_RATE_PERCENT'    => ['section' => 'lexware',     'secret' => false, 'default' => '19'],
    ];

    /** @var array<string,string>|null in-memory cache of the decrypted DB rows */
    private ?array $cache = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionKey = '',
    ) {
    }

    /** @return list<string> every registered key */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    /** Resolved value: non-empty DB value (decrypted) → env → coded default. */
    public function get(string $key): string
    {
        if (!isset(self::REGISTRY[$key])) {
            return '';
        }
        $db = $this->dbValues()[$key] ?? '';
        if ($db !== '') {
            return $db;
        }
        $env = self::envValue($key);
        if ($env !== '') {
            return $env;
        }
        return self::REGISTRY[$key]['default'];
    }

    /**
     * Masked, section-grouped view for the admin UI. Never leaks a raw secret:
     * secret entries carry only `configured`/`last4`, non-secret entries carry
     * the full `value`. `source` is db|env|unset.
     *
     * @return array{sections: array<string, array<string, array<string,mixed>>>, encryptionAvailable: bool}
     */
    public function publicState(): array
    {
        $sections = [];
        foreach (self::REGISTRY as $key => $meta) {
            $db = $this->dbValues()[$key] ?? '';
            $env = self::envValue($key);
            $source = $db !== '' ? 'db' : ($env !== '' ? 'env' : 'unset');
            $value = $source === 'db' ? $db : ($source === 'env' ? $env : $meta['default']);

            $entry = [
                'secret' => $meta['secret'],
                'configured' => $value !== '',
                'source' => $source,
            ];
            if ($meta['secret']) {
                $entry['last4'] = $value !== '' ? substr($value, -4) : '';
            } else {
                $entry['value'] = $value;
            }
            $sections[$meta['section']][$key] = $entry;
        }

        return [
            'sections' => $sections,
            'encryptionAvailable' => $this->encryptionKey !== '',
        ];
    }

    /**
     * Persist a batch. Unknown keys are ignored (closed surface). A blank
     * secret value means "keep the existing one" — so the masked UI never has
     * to round-trip the real secret; a blank non-secret clears the override
     * (the key then falls back to env/default).
     *
     * @param array<string,string> $values
     */
    public function put(array $values): void
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO app_setting (setting_key, setting_value) VALUES (:k, :v) '
            . 'ON DUPLICATE KEY UPDATE setting_value = :v2'
        );
        foreach ($values as $key => $raw) {
            if (!isset(self::REGISTRY[$key])) {
                continue;
            }
            $val = (string) $raw;
            $secret = self::REGISTRY[$key]['secret'];
            if ($secret && $val === '') {
                continue; // keep existing secret
            }
            $stored = ($secret && $val !== '') ? $this->encrypt($val) : $val;
            $upsert->execute(['k' => $key, 'v' => $stored, 'v2' => $stored]);
        }
        $this->cache = null;
    }

    /** @return array<string,string> decrypted DB values keyed by setting_key */
    private function dbValues(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        try {
            $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM app_setting');
            if ($stmt !== false) {
                foreach ($stmt->fetchAll() as $row) {
                    $key = (string) $row['setting_key'];
                    if (!isset(self::REGISTRY[$key])) {
                        continue;
                    }
                    $stored = (string) $row['setting_value'];
                    $out[$key] = (self::REGISTRY[$key]['secret'] && $stored !== '')
                        ? $this->decrypt($stored)
                        : $stored;
                }
            }
        } catch (\Throwable) {
            // Table not migrated yet / DB blip — fall back to env-only so a
            // real action degrades to .env instead of 500'ing mid-deploy.
            $out = [];
        }
        return $this->cache = $out;
    }

    private static function envValue(string $key): string
    {
        $v = $_ENV[$key] ?? false;
        if ($v === false) {
            $v = getenv($key);
        }
        return $v === false ? '' : (string) $v;
    }

    /** AES-256-GCM → 'gcm:' . base64(iv|tag|ciphertext). Plaintext when no key. */
    private function encrypt(string $plain): string
    {
        if ($this->encryptionKey === '') {
            return $plain;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::deriveKey($this->encryptionKey), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'gcm:')) {
            return $stored; // plaintext (dev, or written before a key existed)
        }
        if ($this->encryptionKey === '') {
            return ''; // encrypted but no key to open it
        }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::deriveKey($this->encryptionKey), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /** AES-256 needs 32 bytes; hash the arbitrary-length configured secret. */
    private static function deriveKey(string $secret): string
    {
        return hash('sha256', $secret, true);
    }
}
