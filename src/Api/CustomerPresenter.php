<?php

declare(strict_types=1);

namespace App\Api;

use App\Booking\DayTime;
use App\Entity\Customer;
use App\Entity\Reservation;

/**
 * JSON shapes for the customer directory.
 *
 * The booking dialogs need just enough of a person to recognise them in a
 * dropdown - name, contact details - so that is what a search result carries.
 */
final class CustomerPresenter
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     phone: string,
     *     email: string|null,
     *     contact: string,
     * }
     */
    public static function summary(Customer $customer): array
    {
        return [
            'id' => (string) $customer->getId(),
            'name' => $customer->getFullName(),
            'phone' => $customer->getPhone(),
            'email' => $customer->getEmail(),
            'contact' => $customer->getContactSummary(),
        ];
    }

    /**
     * A booking as the customer's profile page reads it: when it is, and what
     * was agreed for it.
     *
     * @return array{
     *     id: string,
     *     date: string,
     *     time: string|null,
     *     details: string|null,
     * }
     */
    public static function booking(Reservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getId(),
            'date' => $reservation->getReservedOn()->format(LocalDate::FORMAT),
            'time' => DayTime::toString($reservation->getStartsAt()),
            'details' => $reservation->getDetails(),
        ];
    }

    /**
     * @param iterable<Customer> $customers
     *
     * @return list<array<string, mixed>>
     */
    public static function summaries(iterable $customers): array
    {
        $summaries = [];

        foreach ($customers as $customer) {
            $summaries[] = self::summary($customer);
        }

        return $summaries;
    }
}
