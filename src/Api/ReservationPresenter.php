<?php

declare(strict_types=1);

namespace App\Api;

use App\Booking\DayTime;
use App\Entity\Customer;
use App\Entity\Reservation;

/**
 * Turns domain objects into the JSON shapes the calendar consumes.
 * Events are emitted in FullCalendar's own event format, so the browser can
 * hand the response straight to the calendar without remapping it.
 */
final class ReservationPresenter
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     start: string,
     *     allDay: bool,
     *     extendedProps: array{
     *         details: string|null,
     *         phone: string,
     *         email: string|null,
     *         time: string|null,
     *         customerId: string|null,
     *     }
     * }
     */
    public static function event(Reservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getId(),
            'title' => $reservation->getCustomerName(),
            'start' => $reservation->getReservedOn()->format(LocalDate::FORMAT),
            'allDay' => true,
            'extendedProps' => [
                'details' => $reservation->getDetails(),
                'phone' => $reservation->getCustomerPhone(),
                'email' => $reservation->getCustomerEmail(),
                'time' => DayTime::toString($reservation->getStartsAt()),
                // The profile this booking belongs to, so the edit dialog can
                // show which customer was picked and revise can name it back.
                'customerId' => $reservation->getCustomer()->getId() === null
                    ? null
                    : (string) $reservation->getCustomer()->getId(),
            ],
        ];
    }

    /**
     * @param iterable<Reservation> $reservations
     *
     * @return list<array<string, mixed>>
     */
    public static function events(iterable $reservations): array
    {
        $events = [];

        foreach ($reservations as $reservation) {
            $events[] = self::event($reservation);
        }

        return $events;
    }
}
