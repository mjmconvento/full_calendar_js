<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Tests\ApiTestCase;

/**
 * The customer record behind a booking: how profiles are created, matched and
 * reused, and what the lookup endpoint the booking forms call returns.
 */
final class CustomerApiTest extends ApiTestCase
{
    public function testABookingCreatesAProfileForANewCustomer(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', [
            'customerPhone' => '+63 917 555 0134',
            'customerEmail' => 'ada@example.test',
        ]);

        $ada = $this->profile('ada@example.test');

        self::assertSame('Ada Lovelace', $ada->getFullName());
        self::assertSame('+63 917 555 0134', $ada->getPhone());
    }

    /**
     * The point of the profile: a returning customer is one record, not one
     * record per visit.
     */
    public function testARepeatBookingReusesTheProfileInsteadOfCreatingASecond(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        $this->book('2026-09-27', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);

        self::assertCount(1, $this->customers()->findAll());
        self::assertCount(2, $this->reservationsOf('ada@example.test'));
    }

    public function testABookingWithOnlyANameStillFindsTheExistingProfile(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        // No contact details this time: the name is the only thing to go on.
        $this->book('2026-09-27', 'ada lovelace');

        self::assertCount(1, $this->customers()->findAll(), 'The name match must be case-insensitive.');
    }

    public function testABookingCanNameAnExistingProfileDirectly(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        $ada = $this->profile('ada@example.test');

        // The picker's payload: a name that disagrees with the profile is
        // overridden by the profile the id names.
        $this->book('2026-09-27', 'A. Lovelace', ['customerId' => $ada->getId()]);

        self::assertCount(1, $this->customers()->findAll());
        self::assertCount(2, $this->reservationsOf('ada@example.test'));
    }

    /**
     * A booking form is filled in while the customer is standing there and the
     * optional fields may simply not be asked for, so a blank one means "not
     * given", not "forget what you have".
     */
    public function testABookingFormDoesNotEraseAnOptionalDetailItWasNotGiven(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', [
            'customerPhone' => '555-0100',
            'customerEmail' => 'ada@example.test',
        ]);

        // Same number, so the same person - but no email asked for this time.
        $this->book('2026-09-27', 'Ada Lovelace', ['customerPhone' => '555-0100']);

        $ada = $this->profile('ada@example.test');
        self::assertSame('555-0100', $ada->getPhone());
        self::assertSame(
            'ada@example.test',
            $ada->getEmail(),
            'The stored email must survive a booking form that did not ask for one.',
        );
    }

    /**
     * The phone number is the strongest identifier a booking carries, so the
     * same number reaches the same profile however the name is written.
     */
    public function testTheSamePhoneNumberFindsTheProfileUnderADifferentName(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerPhone' => '555-0100']);
        $this->book('2026-09-27', 'Ada King', ['customerPhone' => '555-0100']);

        self::assertCount(1, $this->customers()->findAll());
        self::assertSame('Ada King', $this->profileNamed('Ada King')->getFullName());
    }

    /**
     * A name is shared by everyone who has it, so a booking that matches only on
     * the name must not be allowed to rewrite a stored contact number. The
     * booking still attaches to the existing profile - that is the better guess
     * - but the details on file are left as the operator last confirmed them.
     */
    public function testANameOnlyMatchDoesNotOverwriteAStoredPhoneNumber(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerPhone' => '555-0100']);
        $this->book('2026-09-27', 'Ada Lovelace', ['customerPhone' => '555-0199']);

        self::assertCount(1, $this->customers()->findAll(), 'The name still identifies the profile to attach to.');
        self::assertSame(
            '555-0100',
            $this->profileNamed('Ada Lovelace')->getPhone(),
            'A typed number does not overwrite a confirmed one; correcting it is an edit on the profile page.',
        );
    }

    public function testABookingFormFillsInAnOptionalDetailTheProfileWasMissing(): void
    {
        $this->book('2026-09-20', 'Alan Turing', ['customerPhone' => '555-0177']);
        $this->book('2026-09-27', 'Alan Turing', [
            'customerPhone' => '555-0177',
            'customerEmail' => 'alan@example.test',
        ]);

        self::assertSame('alan@example.test', $this->profile('alan@example.test')->getEmail());
    }

    /**
     * Revising with the picker names a different profile, which moves the
     * booking rather than renaming the person who used to hold it.
     */
    public function testRevisingCanMoveABookingToAnotherCustomer(): void
    {
        $created = $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        $this->book('2026-09-21', 'Grace Hopper', ['customerEmail' => 'grace@example.test']);
        $graceId = $this->profile('grace@example.test')->getId();

        $revised = $this->json('PATCH', '/api/reservations/'.$created['id'], [
            'customerName' => 'Grace Hopper',
            'customerPhone' => '555-0107',
            'customerId' => $graceId,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Grace Hopper', $revised['title']);
        self::assertSame((string) $graceId, $revised['extendedProps']['customerId']);
        self::assertSame(
            'Ada Lovelace',
            $this->profile('ada@example.test')->getFullName(),
            'The booking moved; the person it moved away from is unchanged.',
        );
    }

    public function testTheEventPayloadNamesTheProfileItBelongsTo(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        $ada = $this->profile('ada@example.test');

        $events = $this->events('start=2026-09-20&end=2026-09-21');

        self::assertSame((string) $ada->getId(), $events[0]['extendedProps']['customerId']);
    }

    public function testCancellingABookingKeepsTheProfile(): void
    {
        $created = $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);

        $this->json('DELETE', '/api/reservations/'.$created['id']);
        self::assertResponseStatusCodeSame(204);

        self::assertNotNull(
            $this->customers()->findOneByEmail('ada@example.test'),
            'A cancelled booking is not a reason to forget the customer.',
        );
    }

    public function testTheLookupSearchesNamePhoneAndEmail(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', [
            'customerPhone' => '+63 917 555 0134',
            'customerEmail' => 'ada@example.test',
        ]);
        $this->book('2026-09-21', 'Grace Hopper', ['customerEmail' => 'grace@example.test']);

        foreach (['Lovelace', 'ada@example', '555 0134'] as $term) {
            $found = $this->lookup($term);
            self::assertSame(['Ada Lovelace'], array_column($found, 'name'), sprintf('Searching "%s" must find Ada.', $term));
        }
    }

    public function testTheLookupFindsNothingForAnUnknownTerm(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace');

        self::assertSame([], $this->lookup('Nobody At All'));
    }

    /**
     * The picker calls this on focus, before anything is typed.
     */
    public function testAnEmptyLookupListsRecentCustomers(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace');
        $this->book('2026-09-21', 'Grace Hopper');

        self::assertCount(2, $this->lookup(''));
    }

    public function testTheLookupRequiresAnAccount(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/customers', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A profile page's detail fields are the whole record: unlike a booking
     * form, an empty optional field here clears what is stored.
     */
    public function testTheProfilePageCanClearAnOptionalDetail(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', [
            'customerPhone' => '555-0100',
            'customerEmail' => 'ada@example.test',
        ]);
        $ada = $this->profile('ada@example.test');

        $this->client->request('GET', '/customers/'.$ada->getId());
        $token = $this->tokenFor($ada->getId());

        $this->client->request('POST', '/customers/'.$ada->getId(), [
            'fullName' => 'Ada Lovelace',
            'phone' => '555-0100',
            'email' => '',
            'notes' => 'Prefers the window table.',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/customers/'.$ada->getId());

        $reloaded = $this->profileNamed('Ada Lovelace');
        self::assertNull($reloaded->getEmail(), 'An empty email field on the profile form removes the address.');
        self::assertSame('Prefers the window table.', $reloaded->getNotes());
    }

    /**
     * The phone number is required, so the profile form cannot be used to blank
     * it out either.
     */
    public function testTheProfilePageRefusesToBlankThePhoneNumber(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerPhone' => '555-0100']);
        $ada = $this->profileNamed('Ada Lovelace');

        $this->client->request('GET', '/customers/'.$ada->getId());
        $token = $this->tokenFor($ada->getId());

        $this->client->request('POST', '/customers/'.$ada->getId(), [
            'fullName' => 'Ada Lovelace',
            'phone' => '',
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('555-0100', $this->profileNamed('Ada Lovelace')->getPhone());
    }

    public function testTheProfilePageRefusesAnInvalidEmailAddress(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace', ['customerEmail' => 'ada@example.test']);
        $ada = $this->profile('ada@example.test');

        $this->client->request('GET', '/customers/'.$ada->getId());
        $token = $this->tokenFor($ada->getId());

        $this->client->request('POST', '/customers/'.$ada->getId(), [
            'fullName' => 'Ada Lovelace',
            'email' => 'not-an-email',
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'ada@example.test',
            $this->profile('ada@example.test')->getEmail(),
            'A rejected form must not have written anything.',
        );
    }

    public function testTheProfilePageRequiresTheTokenToSave(): void
    {
        $this->book('2026-09-20', 'Ada Lovelace');
        $ada = $this->profileNamed('Ada Lovelace');

        $this->client->request('POST', '/customers/'.$ada->getId(), [
            'fullName' => 'Someone Else',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Ada Lovelace', $this->profileNamed('Ada Lovelace')->getFullName());
    }

    /**
     * The profile form's own token. The navbar's sign-out form carries one too,
     * so the selector names the form.
     */
    private function tokenFor(?int $customerId): string
    {
        $token = $this->client->getCrawler()
            ->filter(sprintf('form[action="/customers/%d"] input[name="_csrf_token"]', $customerId));

        self::assertCount(1, $token);

        return (string) $token->attr('value');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookup(string $term): array
    {
        $response = $this->json('GET', '/api/customers?q='.rawurlencode($term));

        self::assertResponseIsSuccessful();

        return $response['customers'];
    }

    /**
     * @return list<\App\Entity\Reservation>
     */
    private function reservationsOf(string $email): array
    {
        $customer = $this->profile($email);

        return $this->entityManager
            ->getRepository(\App\Entity\Reservation::class)
            ->findForCustomer($customer);
    }

    /**
     * A profile that must exist. Failing here says which lookup went missing,
     * rather than surfacing as "cannot call a method on null" further down.
     */
    private function profile(string $email): Customer
    {
        $customer = $this->customers()->findOneByEmail($email);

        self::assertInstanceOf(Customer::class, $customer, sprintf('Expected a customer for "%s".', $email));

        return $customer;
    }

    /**
     * A profile found by name, for the tests whose booking did not carry an
     * email address to look up.
     */
    private function profileNamed(string $name): Customer
    {
        $match = $this->customers()->findByIdentity($name, $this->phoneFor($name), null);

        self::assertNotNull($match, sprintf('Expected a customer named "%s".', $name));

        return $match->customer;
    }

    private function customers(): CustomerRepository
    {
        /** @var CustomerRepository $repository */
        $repository = static::getContainer()->get(CustomerRepository::class);

        return $repository;
    }
}
