<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one place accounts are created.
 *
 * Hashing lives here rather than in the controller so the entity can keep its
 * password private and never hold a plaintext value, even briefly.
 */
final readonly class UserRegistrar
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function emailIsTaken(string $email): bool
    {
        return $this->users->findOneByEmail($email) !== null;
    }

    /**
     * Creates the account and flushes it.
     *
     * The caller validates the submitted email first; the unique index on
     * `app_user.email` is the backstop, and a request that loses the race gets
     * a plain English failure instead of a database error page.
     *
     * @throws EmailAlreadyRegisteredException when the address was registered meanwhile
     */
    public function register(
        string $email,
        string $firstName,
        ?string $middleName,
        string $lastName,
        string $plainPassword,
    ): User {
        $user = new User($email, $firstName, $middleName, $lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new EmailAlreadyRegisteredException($user->getEmail(), $e);
        }

        return $user;
    }
}
