<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\FormViolations;
use App\Api\RegistrationForm;
use App\Security\EmailAlreadyRegisteredException;
use App\Security\EmailVerifier;
use App\Security\UserRegistrar;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Creating an account.
 *
 * Registration is open: this is a single organisation's booking calendar, and
 * whoever runs it can reach the sign-up page. A deployment that should not
 * accept new accounts can add an invite check here without touching anything
 * else, since account creation is funnelled through App\Security\UserRegistrar.
 *
 * What sign-up does not do is sign in. The account exists once the form is
 * accepted, but it cannot be used until the link emailed to the address has
 * been followed (App\Security\VerifiedEmailChecker enforces that at every
 * sign-in), so the form hands over to the "check your inbox" page instead of
 * the dashboard. Following the link is what signs the new account in - see
 * App\Controller\EmailVerificationController.
 */
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRegistrar $registrar,
        private readonly EmailVerifier $verifier,
        private readonly FormViolations $violations,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->page();
        }

        if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
            // 400, not the default 200: the request was refused, and a form
            // re-render with a success status would tell a client otherwise.
            return $this->page(['_' => 'Your session expired. Please try again.'], [], Response::HTTP_BAD_REQUEST);
        }

        $form = RegistrationForm::fromRequest($request);
        $errors = $this->violations->flatten($form);

        if ($errors === [] && $this->registrar->emailIsTaken($form->email)) {
            $errors['email'] = 'That email address is already registered.';
        }

        if ($errors !== []) {
            return $this->page($errors, $this->values($form), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $user = $this->registrar->register(
                $form->email,
                $form->firstName,
                $form->middleName,
                $form->lastName,
                $form->password,
            );
        } catch (EmailAlreadyRegisteredException) {
            // Two sign-ups for the same address landed at once; the unique
            // index decided, and the other request's account is the real one.
            return $this->page(
                ['email' => 'That email address is already registered.'],
                $this->values($form),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->verifier->send($user);
        } catch (TransportExceptionInterface $e) {
            // The account exists; only the email failed. The next page can
            // send it again, so this is not worth failing the sign-up over.
            $this->logger->error('The verification email for {email} could not be sent.', [
                'email' => $user->getEmail(),
                'exception' => $e,
            ]);
            $this->addFlash('danger', 'Your account was created, but the verification email could not be sent. Ask for a new link below.');
        }

        // The next page names the address the link went to and can send it
        // again, without carrying the address in its URL.
        $request->getSession()->set(EmailVerificationController::PENDING_EMAIL, $user->getEmail());

        return $this->redirectToRoute('app_verify_email_sent');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     */
    private function page(array $errors = [], array $values = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('security/register.html.twig', [
            'errors' => $errors,
            'values' => $values,
        ], new Response(status: $status));
    }

    /**
     * @return array<string, string>
     */
    private function values(RegistrationForm $form): array
    {
        return [
            'email' => $form->email,
            'firstName' => $form->firstName,
            'middleName' => $form->middleName,
            'lastName' => $form->lastName,
        ];
    }
}
