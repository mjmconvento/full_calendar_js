<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The demo account, so a freshly loaded database can be signed into.
 *
 * This is demo data with a published password, exactly like the demo bookings
 * above: it exists so `make fixtures` leaves a working app, and it must not
 * survive into a deployment that anyone else can reach. Register a real account
 * and delete this one.
 */
final class UserFixtures extends Fixture
{
    public const string DEMO_EMAIL = 'manager@booking-calendar.test';
    public const string DEMO_PASSWORD = 'booking-calendar';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User(self::DEMO_EMAIL, 'Demo', null, 'Manager');
        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        // Nobody reads manager@booking-calendar.test, so the demo account skips the
        // verification email that a real sign-up has to answer.
        $user->markVerified($user->getCreatedAt());

        $manager->persist($user);
        $manager->flush();
    }
}
