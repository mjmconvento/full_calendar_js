<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Email;

/**
 * Signing up, signing in and being kept out.
 *
 * The 2016 application had none of this: every page and the whole JSON API were
 * reachable by anyone who guessed the URL, so these tests pin down that they no
 * longer are.
 *
 * Every token is read from the page that renders it, the way a browser gets it,
 * rather than minted from the CSRF service: a form whose token no longer
 * matches the field it is submitted in is exactly the kind of breakage these
 * tests exist to catch.
 */
final class SecurityTest extends ApiTestCase
{
    public function testAnonymousVisitorIsSentToTheSignInForm(): void
    {
        $this->signOutForTest();

        foreach (['/', '/dashboard', '/customers', '/day/2026-09-11'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/login', null, sprintf('%s must not be public.', $path));
        }
    }

    /**
     * The calendar's fetch client asks for JSON. A redirect would hand it the
     * sign-in page's HTML under a 200 status, which reads as an empty calendar
     * plus a meaningless error message.
     */
    public function testTheApiAnswersAnExpiredClientWithUnauthorizedJson(): void
    {
        $this->signOutForTest();

        $this->client->request('GET', '/api/reservations', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testSigningUpSendsAVerificationLinkInsteadOfSigningIn(): void
    {
        $this->signOutForTest();

        $this->register('Rowan@Example.Test');

        self::assertResponseRedirects('/verify-email/sent');
        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->lastEmail(), 'To', 'rowan@example.test');

        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user, 'The address is stored lower-cased, so signing in is case-insensitive.');
        self::assertFalse($user->isVerified(), 'A sign-up is not verified until its link is followed.');
        self::assertNotSame('a-good-password', $user->getPassword(), 'The password must never be stored as given.');

        // The next page names the address the link went to, and nothing is
        // signed in yet.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'rowan@example.test');

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testAnUnverifiedAccountCannotSignInEvenWithTheRightPassword(): void
    {
        $this->signOutForTest();
        $this->register('rowan@example.test');

        $this->client->request('POST', '/login', [
            '_username' => 'rowan@example.test',
            '_password' => 'a-good-password',
            '_csrf_token' => $this->tokenOn('/login', 'form[action="/login"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Verify your email address before signing in.');
        self::assertCount(
            1,
            $crawler->filter('form[action="/verify-email/resend"] input[name="email"][value="rowan@example.test"]'),
            'The password matched, so the form may offer to send the link again.',
        );

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testTheEmailedLinkVerifiesTheAccountAndSignsItInOnce(): void
    {
        $this->signOutForTest();
        $this->register('rowan@example.test');
        $link = $this->emailedLink();

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/dashboard');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-user', 'Rowan Operator');
        self::assertSelectorTextContains('.alert-success', 'rowan@example.test is verified.');

        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isVerified());

        // Once used, the link is inert: it must not be a way back in for
        // whoever finds it in a mailbox later.
        $this->signOutForTest();
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testATamperedOrExpiredLinkIsRefused(): void
    {
        $this->signOutForTest();
        $this->register('rowan@example.test');
        $link = $this->emailedLink();

        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user);

        // Pointing a genuine link at another account breaks its signature.
        $tampered = str_replace(sprintf('id=%d', $user->getId()), 'id=1', $link);
        self::assertNotSame($link, $tampered);

        $this->client->request('GET', $tampered);
        self::assertResponseRedirects('/verify-email/sent');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'not valid');

        // A correctly signed link whose time has passed is refused as expired,
        // so the page can say "ask for a new one" rather than imply tampering.
        $expired = static::getContainer()->get(UriSigner::class)->sign(
            sprintf('http://localhost/verify-email?id=%d', $user->getId()),
            new \DateTimeImmutable('-1 second'),
        );

        $this->client->request('GET', $expired);
        self::assertResponseRedirects('/verify-email/sent');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'expired');

        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken(), 'A refused link must not sign anyone in.');
    }

    /**
     * The form is public, so it says the same thing to everyone and sends at
     * most one email a minute per address: otherwise it would be a way both to
     * list accounts and to flood a stranger's inbox.
     */
    public function testAskingForANewLinkIsThrottledAndTellsEveryoneTheSameThing(): void
    {
        $this->signOutForTest();
        $this->register('rowan@example.test');

        $sentAgain = 'a new link is on its way';

        // Seconds after the first email: nothing more is sent.
        $this->resend('rowan@example.test');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $sentAgain);

        // Once the previous email is old enough, a new one goes out.
        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user);
        $user->markVerificationSent(new \DateTimeImmutable('-2 minutes'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->resend('rowan@example.test');
        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->lastEmail(), 'To', 'rowan@example.test');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $sentAgain);

        // An address nobody registered, and one already verified: the same
        // sentence, and no email.
        $this->resend('nobody@example.test');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $sentAgain);

        $this->resend(self::EMAIL);
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $sentAgain);
    }

    public function testASignUpWithoutAMatchingConfirmationIsRefused(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/register', [
            'firstName' => 'Rowan',
            'lastName' => 'Operator',
            'email' => 'rowan@example.test',
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-different-password',
            '_csrf_token' => $this->tokenOn('/register', 'form[action="/register"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The two passwords do not match.', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->users()->findOneByEmail('rowan@example.test'));
    }

    /**
     * A first and a last name are required; a middle name is not, because
     * plenty of people have none. When given it is kept, and the account is
     * addressed as "First Last".
     */
    public function testASignUpNeedsAFirstAndLastNameButNotAMiddleName(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/register', [
            'firstName' => 'Rowan',
            'middleName' => 'Quinn',
            'lastName' => '   ',
            'email' => 'rowan@example.test',
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-good-password',
            '_csrf_token' => $this->tokenOn('/register', 'form[action="/register"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Your last name is required.');
        self::assertNull($this->users()->findOneByEmail('rowan@example.test'));

        $this->client->request('POST', '/register', [
            'firstName' => ' Rowan ',
            'middleName' => ' Quinn ',
            'lastName' => 'Operator',
            'email' => 'rowan@example.test',
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-good-password',
            '_csrf_token' => $this->tokenOn('/register', 'form[action="/register"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseRedirects('/verify-email/sent');

        $user = $this->users()->findOneByEmail('rowan@example.test');
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Rowan', $user->getFirstName());
        self::assertSame('Quinn', $user->getMiddleName());
        self::assertSame('Operator', $user->getLastName());
        self::assertSame('Rowan Operator', $user->getDisplayName());
    }

    public function testASignUpWithoutATokenIsRefused(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/register', [
            'firstName' => 'Rowan',
            'lastName' => 'Operator',
            'email' => 'rowan@example.test',
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-good-password',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->users()->findOneByEmail('rowan@example.test'));
    }

    public function testAnAddressCannotBeRegisteredTwice(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/register', [
            'firstName' => 'Someone',
            'lastName' => 'Else',
            'email' => self::EMAIL,
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-good-password',
            '_csrf_token' => $this->tokenOn('/register', 'form[action="/register"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already registered', (string) $this->client->getResponse()->getContent());
    }

    public function testSigningInWithTheWrongPasswordFailsWithoutRevealingTheAccount(): void
    {
        $this->signOutForTest();

        $this->signInThroughTheForm('not-the-password');

        $this->client->followRedirect();
        self::assertStringContainsString(
            'Invalid credentials.',
            (string) $this->client->getResponse()->getContent(),
            'A wrong password and an unknown address must read identically.',
        );
    }

    public function testSigningInWithTheRightPasswordReachesTheDashboard(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
            '_csrf_token' => $this->tokenOn('/login', 'form[action="/login"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseRedirects('/dashboard');
    }

    public function testSigningInWithoutATokenIsRefused(): void
    {
        $this->signOutForTest();

        $this->client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/login');
    }

    public function testTheSignInFormIsNotShownToSomeoneAlreadySignedIn(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/dashboard');
    }

    /**
     * A GET sign-out can be triggered by any page that manages to load /logout
     * as an image, so the route only exists as a CSRF-protected POST.
     */
    public function testSigningOutNeedsTheToken(): void
    {
        $this->client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful('A logout without a token must not end the session.');
    }

    public function testSigningOutEndsTheSession(): void
    {
        $token = $this->tokenOn('/dashboard', 'form[action="/logout"] input[name="_csrf_token"]');

        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    private function signInThroughTheForm(string $password): void
    {
        $token = $this->tokenOn('/login', 'form[action="/login"] input[name="_csrf_token"]');

        $this->client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
    }

    /**
     * Submits the sign-up form for Rowan Operator - no middle name, which
     * the form must accept - with a valid token.
     */
    private function register(string $email): void
    {
        $this->client->request('POST', '/register', [
            'firstName' => 'Rowan',
            'lastName' => 'Operator',
            'email' => $email,
            'password' => 'a-good-password',
            'passwordConfirmation' => 'a-good-password',
            '_csrf_token' => $this->tokenOn('/register', 'form[action="/register"] input[name="_csrf_token"]'),
        ]);
    }

    /**
     * Asks for a new verification link the way the "check your inbox" page
     * does. The redirect back to it is left to the caller: mail assertions
     * see the request that just ran, so they belong before following it.
     */
    private function resend(string $email): void
    {
        $this->client->request('POST', '/verify-email/resend', [
            'email' => $email,
            '_csrf_token' => $this->tokenOn('/verify-email/sent', 'form[action="/verify-email/resend"] input[name="_csrf_token"]'),
        ]);

        self::assertResponseRedirects('/verify-email/sent');
    }

    private function lastEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * The verification link as the recipient would see it, read from the plain
     * text part of the message sent by the last request.
     */
    private function emailedLink(): string
    {
        $body = (string) $this->lastEmail()->getTextBody();

        if (preg_match('~https?://\S+/verify-email\?\S+~', $body, $matches) !== 1) {
            self::fail('The email must carry the verification link.');
        }

        return $matches[0];
    }

    /**
     * Drops the session cookie, which is what "signed out" means to a browser.
     */
    private function signOutForTest(): void
    {
        $this->client->getCookieJar()->clear();
    }

    /**
     * Reads the token the page renders for one specific form. The navbar's
     * sign-out form carries a token too, so the selector has to name the form
     * rather than just the field name.
     */
    private function tokenOn(string $path, string $selector): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter($selector);

        self::assertCount(1, $token, sprintf('Expected one CSRF token at %s matching "%s".', $path, $selector));

        return (string) $token->attr('value');
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }
}
