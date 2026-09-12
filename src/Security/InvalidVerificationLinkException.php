<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A verification link that cannot be honoured: its signature does not match,
 * it names an account that no longer exists, or it has expired. The last case
 * is told apart so the page can say "ask for a new one" instead of implying
 * the link was tampered with.
 */
final class InvalidVerificationLinkException extends \RuntimeException
{
    private function __construct(public readonly bool $expired, string $message, ?\Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function expired(?\Throwable $previous = null): self
    {
        return new self(true, 'The verification link has expired.', $previous);
    }

    public static function invalid(?\Throwable $previous = null): self
    {
        return new self(false, 'The verification link is not valid.', $previous);
    }
}
