<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\EmailVerifier;
use App\Security\InvalidVerificationLinkException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The email round-trip between signing up and being able to sign in.
 *
 * Three public pages, all reachable without a session because the person on
 * them does not have one yet:
 *
 *   /verify-email/sent    - "check your inbox", with a way to ask again
 *   /verify-email/resend  - sends another link, saying the same thing whether
 *                           or not an account was found
 *   /verify-email         - the link itself; marks the account verified and,
 *                           the first time, signs it in
 *
 * Signing in from the link is deliberate: possession of the link is exactly
 * the proof of control over the address that was asked for, and the person
 * following it set the password moments ago. A link that has already done its
 * work does not sign anyone in again, so a used link found later in a mailbox
 * or a browser history is not a way in.
 */
final class EmailVerificationController extends AbstractController
{
    /**
     * Session key holding the address a link was last sent to, so the "check
     * your inbox" page can name it and offer to send it again.
     */
    public const string PENDING_EMAIL = 'app.pending_verification_email';

    public function __construct(
        private readonly EmailVerifier $verifier,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/verify-email/sent', name: 'app_verify_email_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        return $this->render('security/verify_email_sent.html.twig', [
            'email' => $request->getSession()->get(self::PENDING_EMAIL, ''),
            'lifetimeMinutes' => intdiv(EmailVerifier::LINK_LIFETIME_SECONDS, 60),
        ]);
    }

    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resend-verification', $request->request->getString('_csrf_token'))) {
            $this->addFlash('danger', 'Your session expired. Please try again.');

            return $this->redirectToRoute('app_verify_email_sent');
        }

        $email = trim($request->request->getString('email'));

        if ($email !== '') {
            $request->getSession()->set(self::PENDING_EMAIL, $email);
        }

        try {
            $this->verifier->resendTo($email);
            // One sentence for every outcome - sent, throttled, already
            // verified, no such account - or this form would list accounts.
            $this->addFlash('success', 'If that address is waiting to be verified, a new link is on its way. Check your spam folder too.');
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('The verification email for {email} could not be sent.', [
                'email' => $email,
                'exception' => $e,
            ]);
            $this->addFlash('danger', 'The email could not be sent right now. Please try again in a few minutes.');
        }

        return $this->redirectToRoute('app_verify_email_sent');
    }

    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        try {
            $user = $this->verifier->accountFor($request);
        } catch (InvalidVerificationLinkException $e) {
            $this->addFlash('danger', $e->expired
                ? 'That verification link has expired. Ask for a new one below.'
                : 'That verification link is not valid. Ask for a new one below.');

            return $this->redirectToRoute('app_verify_email_sent');
        }

        if ($user->isVerified()) {
            // A second click, or a link a mail scanner fetched before the
            // person did: the work is done, and a used link must not keep
            // signing people in.
            $this->addFlash('success', 'That email address is already verified. Sign in to continue.');

            return $this->redirectToRoute('app_login');
        }

        $this->verifier->confirm($user);
        $request->getSession()->remove(self::PENDING_EMAIL);
        $this->addFlash('success', sprintf('%s is verified.', $user->getEmail()));

        if ($this->getUser() !== null) {
            // Someone is already signed in on this browser - an operator
            // following a link printed by app:verification-link, say. Their
            // session is theirs; the verification is recorded all the same.
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $this->security->login($user, firewallName: 'main');
        } catch (AuthenticationException) {
            // The account is verified; only the shortcut failed. Signing in
            // on the form works, so this is not worth an error page.
            return $this->redirectToRoute('app_login');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
