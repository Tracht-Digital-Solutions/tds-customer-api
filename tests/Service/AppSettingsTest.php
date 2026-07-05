<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tds\CustomerApi\Service\AppSettings;

/**
 * Unit + integration coverage for the runtime settings store.
 *
 * The env-fallback and AES-GCM tests run everywhere (no DB — a throwing PDO
 * mock exercises the graceful "table missing" path, and encrypt/decrypt are
 * poked via reflection). The precedence/masking tests touch a real MariaDB
 * `app_setting` table and skip unless TDS_TEST_DB_DSN is set, matching the
 * repo convention.
 */
final class AppSettingsTest extends TestCase
{
    /** @var list<string> env keys to restore in tearDown */
    private array $touchedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
        $this->touchedEnv = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedEnv[] = $key;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** A PDO whose query() throws — simulates the un-migrated / DB-down path. */
    private function throwingPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new PDOException('no such table'));
        return $pdo;
    }

    public function test_get_falls_back_to_env_when_no_db_value(): void
    {
        $this->setEnv('STRIPE_SECRET_KEY', 'sk_from_env');
        $settings = new AppSettings($this->throwingPdo(), 'enc-key');

        self::assertSame('sk_from_env', $settings->get('STRIPE_SECRET_KEY'));
    }

    public function test_get_falls_back_to_coded_default_when_no_db_and_no_env(): void
    {
        $settings = new AppSettings($this->throwingPdo(), 'enc-key');

        self::assertSame(
            'https://app.tracht-digital.de/invoices',
            $settings->get('STRIPE_RETURN_URL'),
        );
    }

    public function test_get_returns_empty_for_unknown_key(): void
    {
        $settings = new AppSettings($this->throwingPdo(), 'enc-key');
        self::assertSame('', $settings->get('NOT_A_REAL_KEY'));
    }

    public function test_public_state_masks_secrets_and_reports_encryption_flag(): void
    {
        $this->setEnv('STRIPE_SECRET_KEY', 'sk_live_abcd1234');
        $settings = new AppSettings($this->throwingPdo(), 'enc-key');

        $state = $settings->publicState();
        $secretEntry = $state['sections']['stripe']['STRIPE_SECRET_KEY'];

        self::assertTrue($state['encryptionAvailable']);
        self::assertTrue($secretEntry['secret']);
        self::assertTrue($secretEntry['configured']);
        self::assertSame('env', $secretEntry['source']);
        self::assertSame('1234', $secretEntry['last4']);
        self::assertArrayNotHasKey('value', $secretEntry, 'a raw secret must never leave publicState');
    }

    public function test_encryption_available_flag_false_without_key(): void
    {
        $settings = new AppSettings($this->throwingPdo(), '');
        self::assertFalse($settings->publicState()['encryptionAvailable']);
    }

    public function test_aes_gcm_round_trips(): void
    {
        $settings = new AppSettings($this->throwingPdo(), 'my-master-secret');
        $enc = new ReflectionMethod($settings, 'encrypt');
        $dec = new ReflectionMethod($settings, 'decrypt');

        $cipher = $enc->invoke($settings, 'sk_live_supersecret');
        self::assertStringStartsWith('gcm:', $cipher);
        self::assertNotSame('sk_live_supersecret', $cipher);
        self::assertSame('sk_live_supersecret', $dec->invoke($settings, $cipher));
    }

    public function test_encrypt_is_plaintext_without_key(): void
    {
        $settings = new AppSettings($this->throwingPdo(), '');
        $enc = new ReflectionMethod($settings, 'encrypt');
        self::assertSame('plain-value', $enc->invoke($settings, 'plain-value'));
    }

    public function test_decrypt_returns_empty_for_ciphertext_without_key(): void
    {
        $withKey = new AppSettings($this->throwingPdo(), 'k');
        $cipher = (new ReflectionMethod($withKey, 'encrypt'))->invoke($withKey, 'secret');

        $noKey = new AppSettings($this->throwingPdo(), '');
        $dec = new ReflectionMethod($noKey, 'decrypt');
        self::assertSame('', $dec->invoke($noKey, $cipher));
    }

    // --- DB-backed (real MariaDB) ----------------------------------------

    private ?PDO $pdo = null;

    private function db(): PDO
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run app_setting integration tests.');
        }
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                $dsn,
                getenv('TDS_TEST_DB_USER') ?: null,
                getenv('TDS_TEST_DB_PASS') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
        }
        $this->pdo->exec('DROP TABLE IF EXISTS app_setting');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE app_setting (
              setting_key VARCHAR(80) NOT NULL,
              setting_value TEXT,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        return $this->pdo;
    }

    public function test_db_value_wins_over_env(): void
    {
        $pdo = $this->db();
        $this->setEnv('STRIPE_RETURN_URL', 'https://env.example');
        $settings = new AppSettings($pdo, 'enc-key');

        $settings->put(['STRIPE_RETURN_URL' => 'https://db.example']);
        self::assertSame('https://db.example', (new AppSettings($pdo, 'enc-key'))->get('STRIPE_RETURN_URL'));
    }

    public function test_secret_is_encrypted_at_rest_but_get_returns_plaintext(): void
    {
        $pdo = $this->db();
        $settings = new AppSettings($pdo, 'enc-key');
        $settings->put(['STRIPE_SECRET_KEY' => 'sk_live_secret_value']);

        $raw = $pdo->query("SELECT setting_value FROM app_setting WHERE setting_key = 'STRIPE_SECRET_KEY'")->fetchColumn();
        self::assertStringStartsWith('gcm:', (string) $raw, 'secret must be stored as ciphertext');
        self::assertStringNotContainsString('sk_live_secret_value', (string) $raw);

        self::assertSame('sk_live_secret_value', (new AppSettings($pdo, 'enc-key'))->get('STRIPE_SECRET_KEY'));
    }

    public function test_blank_secret_keeps_existing_value(): void
    {
        $pdo = $this->db();
        $settings = new AppSettings($pdo, 'enc-key');
        $settings->put(['STRIPE_SECRET_KEY' => 'sk_original']);
        $settings->put(['STRIPE_SECRET_KEY' => '']); // blank = keep

        self::assertSame('sk_original', (new AppSettings($pdo, 'enc-key'))->get('STRIPE_SECRET_KEY'));
    }

    public function test_blank_non_secret_clears_the_override(): void
    {
        $pdo = $this->db();
        $this->setEnv('STRIPE_RETURN_URL', 'https://env.example');
        $settings = new AppSettings($pdo, 'enc-key');
        $settings->put(['STRIPE_RETURN_URL' => 'https://db.example']);
        $settings->put(['STRIPE_RETURN_URL' => '']); // cleared → back to env

        self::assertSame('https://env.example', (new AppSettings($pdo, 'enc-key'))->get('STRIPE_RETURN_URL'));
    }

    public function test_unknown_keys_are_ignored_on_write(): void
    {
        $pdo = $this->db();
        $settings = new AppSettings($pdo, 'enc-key');
        $settings->put(['NOT_A_REAL_KEY' => 'x']);

        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM app_setting')->fetchColumn());
    }
}
