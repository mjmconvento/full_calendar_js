<?php

declare(strict_types=1);

namespace App\Booking;

use App\Repository\CustomerRepository;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;

/**
 * Builds the dashboard's counters.
 *
 * The clock is injected rather than read from `new \DateTimeImmutable()` so
 * "today" is decided in exactly one place and is testable without touching the
 * system clock.
 */
final readonly class DashboardMetrics
{
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerRepository $customers,
        private ClockInterface $clock,
    ) {
    }

    public function current(): DashboardStats
    {
        $today = $this->clock->now()->setTime(0, 0);

        return new DashboardStats(
            today: $today,
            todayBooked: $this->reservations->countOn($today),
            tomorrowBooked: $this->reservations->countOn($today->modify('+1 day')),
            weekBooked: $this->reservations->countRange($today, $today->modify('+7 days')),
            upcomingBooked: $this->reservations->countFrom($today),
            totalBooked: $this->reservations->countAll(),
            customerCount: $this->customers->countAll(),
        );
    }
}
