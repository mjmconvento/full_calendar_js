<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Raised when two requests try to register the same address at once. The
 * pre-flight check in the registration form has already passed by then.
 */
final class EmailAlreadyRegisteredException extends \RuntimeException
{
    public function __construct(public readonly string $email, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('"%s" is already registered.', $email), 0, $previous);
    }
}
