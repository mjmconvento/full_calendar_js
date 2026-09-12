<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Reservation;

/**
 * One day of the calendar with everything the day page shows: the date, and the
 * bookings themselves in the order they are read.
 */
final readonly class DaySchedule
{
    /**
     * @param list<Reservation> $reservations
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $reservations,
    ) {
    }

    public function count(): int
    {
        return count($this->reservations);
    }

    public function isEmpty(): bool
    {
        return $this->reservations === [];
    }
}
