<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

/**
 * Covers the contracts the calendar depends on, including the ones the legacy
 * PHP version got wrong: the fetch query used an inclusive `BETWEEN` upper
 * bound, so it leaked one extra day.
 */
final class ReservationApiTest extends ApiTestCase
{
    /**
     * The daily cap was the legacy app's headline rule and is gone: a day takes
     * as many bookings as it is given.
     */
    public function testADayAcceptsMoreBookingsThanTheOldLimitOfTwo(): void
    {
        foreach (['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Radia Perlman', 'Barbara Liskov'] as $i => $name) {
            $this->book('2026-09-10', $name, ['customerPhone' => '+63 917 555 01'.str_pad((string) $i, 2, '0', \STR_PAD_LEFT)]);
        }

        self::assertCount(5, $this->events('start=2026-09-10&end=2026-09-11'));
    }

    public function testRangeQueryTreatsTheEndDateAsExclusive(): void
    {
        $this->book('2026-09-30', 'September guest', ['customerPhone' => '555-0101']);
        $this->book('2026-10-01', 'October guest', ['customerPhone' => '555-0102']);

        $events = $this->events('start=2026-09-01&end=2026-10-01');

        self::assertSame(['September guest'], array_column($events, 'title'));
    }

    public function testEventsAreReturnedInFullCalendarShape(): void
    {
        $this->book('2026-09-12', 'Ada Lovelace', [
            'customerPhone' => '+63 917 555 0134',
            'customerEmail' => 'ada@example.test',
            'startsAt' => '19:00',
            'details' => '  Window table  ',
        ]);

        $events = $this->events('start=2026-09-12&end=2026-09-13');

        self::assertSame([
            'id' => $events[0]['id'],
            'title' => 'Ada Lovelace',
            'start' => '2026-09-12',
            'allDay' => true,
            'extendedProps' => [
                'details' => 'Window table',
                'phone' => '+63 917 555 0134',
                'email' => 'ada@example.test',
                'time' => '19:00',
                'customerId' => $events[0]['extendedProps']['customerId'],
            ],
        ], $events[0]);
    }

    public function testEmailAndTimeAreOptional(): void
    {
        $this->book('2026-09-12', 'Ada Lovelace', ['customerPhone' => '555-0100']);

        $events = $this->events('start=2026-09-12&end=2026-09-13');

        self::assertNull($events[0]['extendedProps']['email']);
        self::assertNull($events[0]['extendedProps']['time'], 'A booking with no time agreed stores null, not midnight.');
    }

    public function testASingleDigitHourIsAcceptedAndNormalised(): void
    {
        // "9:30" is what a person writes; the API answers with "09:30".
        $this->book('2026-09-12', 'Ada Lovelace', ['customerPhone' => '555-0100', 'startsAt' => '9:30']);

        self::assertSame('09:30', $this->events('start=2026-09-12&end=2026-09-13')[0]['extendedProps']['time']);
    }

    /**
     * A time outside the clock is a rejection, not a booking quietly moved to
     * the next day: PHP reads "25:00" as 01:00 tomorrow.
     */
    public function testATimeOutsideTheClockIsRejected(): void
    {
        $this->json('POST', '/api/reservations', [
            'date' => '2026-09-12',
            'customerName' => 'Ada Lovelace',
            'customerPhone' => '555-0100',
            'startsAt' => '25:00',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testABookingWithoutAPhoneNumberIsRejected(): void
    {
        $problem = $this->json('POST', '/api/reservations', [
            'date' => '2026-09-12',
            'customerName' => 'Ada Lovelace',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame("The customer's phone number is required.", $problem['violations'][0]['title']);
    }

    public function testAPhoneNumberThatIsNotAPhoneNumberIsRejected(): void
    {
        $this->json('POST', '/api/reservations', [
            'date' => '2026-09-12',
            'customerName' => 'Ada Lovelace',
            'customerPhone' => 'call me maybe',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnInvalidEmailAddressIsRejected(): void
    {
        $this->json('POST', '/api/reservations', [
            'date' => '2026-09-12',
            'customerName' => 'Ada Lovelace',
            'customerPhone' => '555-0100',
            'customerEmail' => 'not-an-email',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testReservationCanBeRevised(): void
    {
        $created = $this->book('2026-09-14', 'Ada Lovelace', ['customerPhone' => '555-0100', 'details' => 'Window table']);

        $revised = $this->json('PATCH', '/api/reservations/'.$created['id'], [
            'customerName' => 'Ada Byron',
            'customerPhone' => '+1 (555) 010-0102',
            'customerEmail' => 'ada@example.test',
            'startsAt' => '18:30',
            'details' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Ada Byron', $revised['title']);
        self::assertSame('+1 (555) 010-0102', $revised['extendedProps']['phone']);
        self::assertSame('ada@example.test', $revised['extendedProps']['email']);
        self::assertSame('18:30', $revised['extendedProps']['time']);
        self::assertNull($revised['extendedProps']['details']);
        self::assertSame('2026-09-14', $revised['start'], 'Revising must not move the booking.');
    }

    /**
     * A booking form records what is known, so an optional field left blank on a
     * revise keeps the stored value rather than deleting it. Clearing contact
     * details is an edit to the customer's profile, and the profile page is
     * where that happens (see CustomerApiTest::testTheProfilePageCanClearADetail).
     */
    public function testRevisingWithoutAnOptionalFieldKeepsIt(): void
    {
        $created = $this->book('2026-09-14', 'Ada Lovelace', [
            'customerPhone' => '555-0100',
            'customerEmail' => 'ada@example.test',
        ]);

        $revised = $this->json('PATCH', '/api/reservations/'.$created['id'], [
            'customerName' => 'Ada Lovelace',
            'customerPhone' => '555-0100',
        ]);

        self::assertSame('ada@example.test', $revised['extendedProps']['email']);
    }

    public function testRevisingWithoutAPhoneNumberIsRejected(): void
    {
        $created = $this->book('2026-09-14', 'Ada Lovelace', ['customerPhone' => '555-0100']);

        $this->json('PATCH', '/api/reservations/'.$created['id'], ['customerName' => 'Ada Lovelace']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCancellingRemovesTheReservation(): void
    {
        $created = $this->book('2026-09-15', 'Ada Lovelace', ['customerPhone' => '555-0100']);

        $this->json('DELETE', '/api/reservations/'.$created['id']);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->events('start=2026-09-15&end=2026-09-16'));
    }

    public function testUnknownReservationIsNotFound(): void
    {
        $this->json('PATCH', '/api/reservations/424242', [
            'customerName' => 'Nobody',
            'customerPhone' => '555-0100',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBookingWithoutACustomerNameIsRejected(): void
    {
        $problem = $this->json('POST', '/api/reservations', [
            'date' => '2026-09-16',
            'customerName' => '   ',
            'customerPhone' => '555-0100',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame("The customer's name is required.", $problem['violations'][0]['title']);
    }

    public function testBookingWithAMalformedDateIsRejected(): void
    {
        $this->json('POST', '/api/reservations', [
            'date' => '16/09/2026',
            'customerName' => 'Ada Lovelace',
            'customerPhone' => '555-0100',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * PHP reads "2026-02-30" as the second of March, so a calendar date that
     * does not exist has to be rejected rather than quietly shifted.
     */
    public function testBookingWithAnImpossibleDateIsRejected(): void
    {
        $this->json('POST', '/api/reservations', [
            'date' => '2026-02-30',
            'customerName' => 'Ada Lovelace',
            'customerPhone' => '555-0100',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedRangeIsRejected(): void
    {
        $this->json('GET', '/api/reservations?start=not-a-date&end=2026-10-01');

        self::assertResponseStatusCodeSame(400);
    }
}
