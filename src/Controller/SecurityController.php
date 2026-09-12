<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\EmailNotVerifiedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Signing in and out.
 *
 * The 2016 application had neither: `index.php` was reachable by anyone, and a
 * credential check was not part of the design. The POST that actually performs
 * the sign-in is intercepted by the firewall's form_login authenticator before
 * it ever reaches this controller, which is why `login()` only has to render
 * the form and report what went wrong last time.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Someone with a live session has no use for the form.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            // Kept so the field is not empty after a failed attempt. It is the
            // submitted address, which is not a secret - the password is not
            // retained anywhere.
            'email' => $authenticationUtils->getLastUsername(),
            // The failure message is deliberately the generic "Invalid
            // credentials." rather than "no such account": telling the two
            // apart would turn the form into a way to enumerate accounts.
            'error' => $error?->getMessageKey(),
            // The one failure that gets more than a sentence. It is only ever
            // raised after the password matched, so offering to send the
            // verification link again gives nothing away to anyone else.
            'unverified' => $error instanceof EmailNotVerifiedException,
        ]);
    }

    /**
     * Never executed: the firewall's logout listener answers this path.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the firewall and is never reached.');
    }
}
