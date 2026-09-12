<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use App\Security\UserRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Boots the app against a throwaway SQLite database (see .env.test) built from
 * the entity mapping, so the suite runs without PostgreSQL or Docker.
 *
 * Every request is made by a signed-in account: the whole application sits
 * behind the firewall, so an unauthenticated default would make every test a
 * redirect assertion instead of the thing it means to check. The two tests that
 * are about being signed out say so explicitly.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string EMAIL = 'operator@booking-calendar.test';
    protected const string PASSWORD = 'operator-password';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->signIn();
    }

    /**
     * Creates an account through the same service the sign-up form uses -
     * hashing included - marks its address verified, since a sign-up that has
     * not answered its email cannot sign in, and signs the test browser in
     * as it.
     */
    protected function signIn(string $email = self::EMAIL, string $password = self::PASSWORD): User
    {
        $user = static::getContainer()->get(UserRegistrar::class)->register($email, 'Test', null, 'Operator', $password);
        $user->markVerified($user->getCreatedAt());
        $this->entityManager->flush();

        $this->client->loginUser($user);

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function json(string $method, string $uri, ?array $body = null): mixed
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $content = $this->client->getResponse()->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        return json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Books a day.
     *
     * A phone number is required on every booking, so one is supplied when the
     * caller does not care about it. It is derived from the name, so two
     * different customers do not accidentally share a number - which the
     * resolver would read as the same person.
     *
     * @param array<string, mixed> $customer overrides, e.g. an email or a time
     *
     * @return array<string, mixed> the created event payload
     */
    protected function book(string $date, string $customerName, array $customer = []): array
    {
        // $customer first: PHP's array union keeps the LEFT operand's keys, so
        // this is what lets a caller override the defaults.
        $payload = $this->json('POST', '/api/reservations', $customer + [
            'date' => $date,
            'customerName' => $customerName,
            'customerPhone' => $this->phoneFor($customerName),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * A stable, digits-only phone number per customer name.
     */
    protected function phoneFor(string $customerName): string
    {
        $digits = substr(sprintf('%u', crc32($customerName)), 0, 7);

        return sprintf('+63 917 %s', substr($digits, 0, 3).' '.substr($digits, 3));
    }

    /**
     * The `events` list of a paged response, which is what most tests care
     * about.
     *
     * @return list<array<string, mixed>>
     */
    protected function events(string $query): array
    {
        $page = $this->json('GET', '/api/reservations?'.$query);

        self::assertResponseIsSuccessful();
        self::assertIsArray($page);

        return $page['events'];
    }
}
