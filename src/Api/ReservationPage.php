<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Reservation;

/**
 * One page of bookings, with enough context for a client to ask for the next.
 *
 * Serialised as `{events, page, perPage, total, hasMore}`: the calendar keeps
 * requesting pages until `hasMore` is false, and the dashboard links to the
 * pages of the same result set. `events` stays in FullCalendar's own event
 * shape, so nothing is remapped in the browser.
 */
final readonly class ReservationPage
{
    /**
     * @param list<Reservation> $reservations
     */
    public function __construct(
        public array $reservations,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }

    /**
     * @return array{
     *     events: list<array<string, mixed>>,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     hasMore: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'events' => ReservationPresenter::events($this->reservations),
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'hasMore' => $this->hasMore(),
        ];
    }
}
