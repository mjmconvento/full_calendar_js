<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * The dashboard's counters, in one object so the template never has to reach
 * into a repository itself.
 *
 * There is no capacity here any more: a day takes as many bookings as it is
 * given, so "how full is today" is not a question the data can answer. What the
 * dashboard reports instead is how much is on the books - today, tomorrow, the
 * coming week, still to come, and in total - plus how many people it books for.
 */
final readonly class DashboardStats
{
    public function __construct(
        public \DateTimeImmutable $today,
        public int $todayBooked,
        public int $tomorrowBooked,
        public int $weekBooked,
        public int $upcomingBooked,
        public int $totalBooked,
        public int $customerCount,
    ) {
    }
}
