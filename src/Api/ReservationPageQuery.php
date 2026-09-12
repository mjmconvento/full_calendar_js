<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of `GET /api/reservations`.
 *
 * The calendar used to ask for one calendar month and be handed every booking
 * in it at once. It now asks for a window and a page, so the browser draws the
 * first screenful immediately and pulls the rest in behind it; a busy month
 * never arrives as one response.
 *
 * `start` and `end` are optional - omitting them pages over every booking on
 * file, which is what the tests and any future export want. Range queries are
 * half-open (start inclusive, end exclusive), matching FullCalendar, so the
 * legacy inclusive `BETWEEN` that leaked one extra day is not repeated here.
 */
final readonly class ReservationPageQuery
{
    public const int DEFAULT_PER_PAGE = 10;
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Assert\Date(message: 'The start date must use the YYYY-MM-DD format.')]
        public ?string $start = null,

        #[Assert\Date(message: 'The end date must use the YYYY-MM-DD format.')]
        public ?string $end = null,

        #[Assert\Positive(message: 'The page must be 1 or greater.')]
        public int $page = 1,

        #[Assert\Range(
            min: 1,
            max: self::MAX_PER_PAGE,
            notInRangeMessage: 'Ask for between {{ min }} and {{ max }} bookings per page.',
        )]
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    public function from(): ?\DateTimeImmutable
    {
        return $this->start === null ? null : LocalDate::fromString($this->start);
    }

    public function until(): ?\DateTimeImmutable
    {
        return $this->end === null ? null : LocalDate::fromString($this->end);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
