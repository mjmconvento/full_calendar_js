<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Refuses a sign-in whose credentials were right but whose address has not
 * been verified.
 *
 * It extends the "custom user message" status exception because that is the
 * one kind the authenticator lets through to the form as-is: every other
 * AccountStatusException is replaced by "Invalid credentials." under the
 * default `expose_security_errors: none`. The sign-in form can also tell this
 * failure from a wrong password, and offers to send the link again.
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Verify your email address before signing in. We sent a link to the address you registered with.');
    }
}
