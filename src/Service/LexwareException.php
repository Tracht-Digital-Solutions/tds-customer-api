<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

/**
 * Raised when the Lexware API rejects a request or is unreachable.
 * Carries the upstream HTTP status (0 = transport error) so the action
 * can map it to a sensible response.
 */
final class LexwareException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
