<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Booking\DayTime;
use App\Entity\Customer;
use App\Entity\Reservation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo data: the two rows from the original SQL dump (git history),
 * plus a handful of bookings around today so the calendar is not empty on first
 * load. The day at `today +3` is deliberately filled to the limit so the "day is
 * full" path is easy to try out.
 *
 * The same person is booked twice - Ada Lovelace today and next week - so the
 * customer directory shows a profile with a booking history rather than a list
 * of one-booking rows. Email addresses are deliberately missing on a few
 * people: they are optional, and every page that lists customers has to read
 * sensibly when they are. Phone numbers are not optional.
 */
final class ReservationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $today = new \DateTimeImmutable('today');

        $customers = [
            'millicent' => new Customer('Millicent Convento', '+63 917 555 0101'),
            'jarius' => new Customer('Jarius Mendoza', '+63 917 555 0102'),
            'ada' => new Customer('Ada Lovelace', '+63 917 555 0134', 'ada@example.test', 'Prefers the window table.'),
            'grace' => new Customer('Grace Hopper', '+63 917 555 0155', 'grace@example.test'),
            // No email and no notes: the common case.
            'alan' => new Customer('Alan Turing', '+63 917 555 0177'),
            'radia' => new Customer('Radia Perlman', '555-0100'),
            'barbara' => new Customer('Barbara Liskov', '+1 (555) 010-0102', 'barbara@example.test', 'Opening shift, arrives early.'),
        ];

        foreach ($customers as $customer) {
            $manager->persist($customer);
        }

        $bookings = [
            // Straight from the 2016 dump.
            ['millicent', new \DateTimeImmutable('2016-02-16'), null, null],
            ['jarius', new \DateTimeImmutable('2016-02-16'), null, null],

            ['barbara', $today, '08:45', 'Opening shift.'],
            ['ada', $today->modify('+1 day'), '19:00', 'Window table, arrives at 7pm.'],
            ['grace', $today->modify('+3 days'), '18:30', 'Anniversary dinner.'],
            ['alan', $today->modify('+3 days'), null, 'Vegetarian menu.'],
            ['radia', $today->modify('+7 days'), '12:15', null],
            // A returning customer: the profile page shows two bookings.
            ['ada', $today->modify('+14 days'), '19:00', 'Second visit.'],
            // Same day as Grace: a day is no longer capped, so two bookings on
            // one date is simply two bookings.
            ['radia', $today->modify('+3 days'), '10:00', 'Morning consult.'],
        ];

        foreach ($bookings as [$key, $reservedOn, $time, $details]) {
            $manager->persist(new Reservation(
                $customers[$key],
                $reservedOn,
                DayTime::fromString($time),
                $details,
            ));
        }

        $manager->flush();
    }
}
