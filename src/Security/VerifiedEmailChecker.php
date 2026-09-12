<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Keeps an account out until its email address has been verified.
 *
 * The check runs *after* the credentials have been verified, not before.
 * Symfony runs checkPreAuth() before it looks at the password, so an
 * "unverified" refusal there would tell anyone who typed an address whether
 * an account exists for it - the enumeration the sign-in form's generic
 * "Invalid credentials." message exists to prevent. Only someone who already
 * knows the password learns that the account is waiting on its email.
 *
 * Every way of signing in goes through here: the form, a remember-me cookie,
 * and Security::login() after a verification link is followed.
 */
final class VerifiedEmailChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
