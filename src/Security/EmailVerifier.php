<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Proves that an account's email address reaches its owner.
 *
 * Sign-up emails a link; following it marks the account verified, and until
 * then the account cannot sign in. The link is the verification route's URL,
 * carrying the account id, signed and given an expiry by the framework's own
 * UriSigner - the same HMAC over the whole URL that fragment rendering and
 * signed controller URLs rely on. Nothing is stored for the link itself:
 * forging one means forging the signature, and the id it names is covered by
 * that signature, so a link can only ever verify the account it was sent to.
 *
 * The signature covers the scheme and host, so a link is only valid at the
 * origin it was generated for. In a request that is the request's own host;
 * from the console it is DEFAULT_URI.
 */
final readonly class EmailVerifier
{
    /**
     * An hour: long enough to reach a phone in a pocket, short enough that a
     * link lying in a forwarded email or a shared inbox goes stale.
     */
    public const int LINK_LIFETIME_SECONDS = 3600;

    /**
     * The least time between two emails to one address. Asking for a new link
     * is a public form, and without this it would be a way to flood a
     * stranger's inbox one click at a time - on the free sending plan's budget.
     */
    public const int RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private MailerInterface $mailer,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function verificationUrl(User $user): string
    {
        $url = $this->urlGenerator->generate(
            'app_verify_email',
            ['id' => $user->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign(
            $url,
            $this->clock->now()->modify(sprintf('+%d seconds', self::LINK_LIFETIME_SECONDS)),
        );
    }

    /**
     * Emails a fresh link and records when. If the transport refuses the
     * message nothing is recorded, so the next attempt is not throttled.
     *
     * @throws TransportExceptionInterface when the mail could not be handed over
     */
    public function send(User $user): void
    {
        $email = (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject('Verify your email address')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->textTemplate('emails/verify_email.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'url' => $this->verificationUrl($user),
                'lifetimeMinutes' => intdiv(self::LINK_LIFETIME_SECONDS, 60),
            ]);

        $this->mailer->send($email);

        $user->markVerificationSent($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * Sends another link, unless the address is unknown, already verified, or
     * was emailed less than a minute ago. The caller is told none of that: the
     * form this serves is public, and "we sent it" versus "no such account"
     * would let it be used to list who has an account.
     *
     * @throws TransportExceptionInterface when the mail could not be handed over
     */
    public function resendTo(string $email): void
    {
        $user = $this->users->findOneByEmail($email);

        if ($user === null || $user->isVerified()) {
            return;
        }

        $sentAt = $user->getVerificationSentAt();

        if ($sentAt !== null && $this->clock->now()->getTimestamp() - $sentAt->getTimestamp() < self::RESEND_COOLDOWN_SECONDS) {
            return;
        }

        $this->send($user);
    }

    /**
     * The account a followed link belongs to. The link is checked first, so a
     * guessed or expired one never reaches the database.
     *
     * @throws InvalidVerificationLinkException
     */
    public function accountFor(Request $request): User
    {
        try {
            $this->uriSigner->verify($request);
        } catch (ExpiredSignedUriException $e) {
            throw InvalidVerificationLinkException::expired($e);
        } catch (SignedUriException $e) {
            throw InvalidVerificationLinkException::invalid($e);
        }

        // A genuine link for an account that has since been removed reads, to
        // the person holding it, the same as a link that never was genuine.
        return $this->users->find($request->query->getInt('id'))
            ?? throw InvalidVerificationLinkException::invalid();
    }

    public function confirm(User $user): void
    {
        $user->markVerified($this->clock->now());
        $this->entityManager->flush();
    }
}
