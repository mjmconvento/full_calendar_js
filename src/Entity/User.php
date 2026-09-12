<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account that may sign in to manage the calendar.
 *
 * The legacy application had no accounts at all: `index.php` was open to anyone
 * who could reach the URL, and the only thing standing between the reservations
 * and the public was the assumption that nobody would find it. The 2016
 * database dump had no credentials table either, so this is new schema rather
 * than a port.
 *
 * The table is called `app_user` because `user` is a reserved word on several
 * database engines, PostgreSQL's own `user` (the current role) among them.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const int EMAIL_MAX_LENGTH = 180;
    public const int NAME_MAX_LENGTH = 120;
    public const int PASSWORD_MAX_LENGTH = 4096;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * An account is identified by its address, so an empty one is a programming
     * error rather than a state worth representing: it could never sign in.
     */
    #[ORM\Column(length: self::EMAIL_MAX_LENGTH)]
    private string $email;

    /**
     * The name in three parts, the way the sign-up form asks for it. The
     * first and last names are required; plenty of people have no middle
     * name, so that one is NULL rather than a blank the form would have to
     * insist on. Nothing sorts or searches by these - they are how the app
     * addresses the operator - so there is no separate display column: see
     * getDisplayName().
     */
    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $firstName;

    #[ORM\Column(length: self::NAME_MAX_LENGTH, nullable: true)]
    private ?string $middleName;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $lastName;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /**
     * An empty string means "no usable password": no presented password can
     * verify against it, so such an account cannot be signed into. It is only
     * ever observable between construction and the call to setPassword().
     */
    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * When the address was proven to reach its owner, by following the link
     * emailed at sign-up. NULL means it has not been, and such an account
     * cannot sign in (see App\Security\VerifiedEmailChecker). Accounts that
     * predate the rule were marked verified by the migration that added it.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * When the last verification email went out, so that asking for another
     * one cannot be used to flood the inbox or burn the daily sending quota.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificationSentAt = null;

    public function __construct(
        string $email,
        string $firstName,
        ?string $middleName,
        string $lastName,
        ?\DateTimeImmutable $now = null,
    ) {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new \InvalidArgumentException('An account needs an email address to be identified by.');
        }

        $middleName = trim($middleName ?? '');

        $this->email = $email;
        $this->firstName = trim($firstName);
        $this->middleName = $middleName === '' ? null : $middleName;
        $this->lastName = trim($lastName);
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * "First Last", which is how the navigation and the emails address the
     * account. Tolerates an empty part: accounts that predate the split were
     * backfilled from one free-text name, and a one-word name has no last
     * part to put anywhere.
     */
    public function getDisplayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * Idempotent: a link followed twice, or a scanner that pre-fetches it,
     * must not move the recorded time.
     */
    public function markVerified(\DateTimeImmutable $at): void
    {
        $this->verifiedAt ??= $at;
    }

    public function getVerificationSentAt(): ?\DateTimeImmutable
    {
        return $this->verificationSentAt;
    }

    public function markVerificationSent(\DateTimeImmutable $at): void
    {
        $this->verificationSentAt = $at;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // Every account can at least manage the calendar; nothing is ever
        // signed in without ROLE_USER.
        $roles[] = 'ROLE_USER';

        /** @var list<string> $roles */
        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        // The constructor rejects a blank address, but Doctrine hydrates this
        // property straight from the database and never runs it, so the
        // invariant is re-checked where it is relied on.
        if ($this->email === '') {
            throw new \LogicException(sprintf('User #%s has a blank email address.', $this->id ?? 'new'));
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Expects an already-hashed password; see App\Security\UserRegistrar.
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function eraseCredentials(): void
    {
        // Nothing sensitive is held outside the hash, which must stay readable
        // for the password hasher to verify against.
    }
}
