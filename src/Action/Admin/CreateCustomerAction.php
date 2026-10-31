<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * POST /admin/customers
 *
 * Creates a company (customer profile). Body: {name, email, createLogin?}.
 *
 * By default (`createLogin` true / omitted) it also provisions an initial owner
 * login: generates a temp password and asks tds-auth-api to store the
 * credential as an app_user tied to the new company. The temp password is
 * returned ONCE. Pass `createLogin: false` to create the company only — the
 * admin then adds accounts via tds-auth-api `POST /admin/users` (this is how
 * a company gets several accounts).
 *
 * The owner-login path is wrapped in a DB transaction: if the downstream call
 * to tds-auth-api fails, the customer row is rolled back so we never leave a
 * company whose owner can't log in.
 *
 * Gated by the admin-JWT JwksAuthMiddleware(requireAdmin: true).
 */
final class CreateCustomerAction extends BaseAction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClientInterface $http,
        private readonly string $authApiUrl,
        private readonly string $serviceToken,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $createLogin = !array_key_exists('createLogin', $body) || (bool) $body['createLogin'];

        if ($name === '' || strlen($name) > 200) {
            return $this->json($response, 422, ['error' => 'Name required (1–200 chars)']);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, 422, ['error' => 'Valid email required']);
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO customer (email, name, created_at, updated_at) '
                . 'VALUES (:email, :name, NOW(), NOW())'
            );
            $stmt->execute(['email' => $email, 'name' => $name]);
            $customerId = (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000') {
                return $this->json($response, 409, ['error' => 'A customer with that email already exists']);
            }
            throw $e;
        }

        if (!$createLogin) {
            $this->pdo->commit();
            return $this->json($response, 201, [
                'customer' => ['id' => $customerId, 'name' => $name, 'email' => $email],
            ]);
        }

        $tempPassword = self::generateTempPassword();

        try {
            $authResponse = $this->http->request('POST', rtrim($this->authApiUrl, '/') . '/admin/customer-credentials', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->serviceToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'customer_id' => $customerId,
                    'email' => $email,
                    'password' => $tempPassword,
                    'name' => $name,
                ],
                'http_errors' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $e) {
            $this->pdo->rollBack();
            return $this->json($response, 502, [
                'error' => 'Auth API unreachable',
                'detail' => $e->getMessage(),
            ]);
        }

        if ($authResponse->getStatusCode() !== 201) {
            $this->pdo->rollBack();
            $authBody = (string) $authResponse->getBody();
            return $this->json($response, 502, [
                'error' => 'Auth API rejected credential creation',
                'authStatus' => $authResponse->getStatusCode(),
                'authBody' => $authBody,
            ]);
        }

        $this->pdo->commit();

        return $this->json($response, 201, [
            'customer' => [
                'id' => $customerId,
                'name' => $name,
                'email' => $email,
            ],
            'tempPassword' => $tempPassword,
        ]);
    }

    /**
     * 16-char URL-safe temp password from 12 random bytes. Enough
     * entropy that brute-force is infeasible during the short window
     * before the customer changes it.
     */
    private static function generateTempPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
