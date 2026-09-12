<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place a booking is written.
 *
 * The legacy app capped a day at two reservations in JavaScript, which is why
 * this service used to arbitrate a numbered slot per day against a unique index
 * and raise "the day is full" or "someone took that slot" from here. A day is no
 * longer capped, so all of that is gone: booking a day appends to it, and two
 * simultaneous bookings are simply two bookings.
 */
final readonly class ReservationBooker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationRepository $reservations,
        private CustomerResolver $customers,
    ) {
    }

    public function book(\DateTimeImmutable $date, ReservationInput $input): Reservation
    {
        $reservation = new Reservation(
            $this->customers->resolve($input),
            $date,
            $input->startsAt,
            $input->details,
        );

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
    }

    /**
     * Replaces the booking's own details, and the profile of the customer it is
     * for.
     *
     * Naming a different customer id moves the booking to that person; without
     * one, the customer it already has is updated in place. That second case is
     * what stops "fix the typo in this name" from silently creating a second
     * profile for somebody already on file.
     */
    public function revise(Reservation $reservation, ReservationInput $input): Reservation
    {
        if ($input->customerId !== null && $input->customerId !== $reservation->getCustomer()->getId()) {
            $replacement = $this->customers->byId($input->customerId);

            if ($replacement !== null) {
                $reservation->assignTo($replacement);
            }
        } else {
            $reservation->getCustomer()->absorb($input);
        }

        $reservation->reviseDetails($input->startsAt, $input->details);
        $this->entityManager->flush();

        return $reservation;
    }

    public function cancel(Reservation $reservation): void
    {
        $this->entityManager->remove($reservation);
        $this->entityManager->flush();
    }

    /**
     * One day and everything on it, as the day page and the dashboard need it.
     */
    public function scheduleOn(\DateTimeImmutable $date): DaySchedule
    {
        return new DaySchedule($date->setTime(0, 0), $this->reservations->findOn($date));
    }
}
