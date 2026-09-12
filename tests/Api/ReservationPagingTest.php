<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\ReservationPageQuery;
use App\Tests\ApiTestCase;

/**
 * The calendar draws what it has and asks for the next page while the operator
 * is already looking at the month, so paging has to be exact: a page that
 * silently repeats or skips a booking would render a month with a booking
 * missing from it.
 */
final class ReservationPagingTest extends ApiTestCase
{
    public function testTheListIsReturnedOnePageAtATime(): void
    {
        foreach ([1, 2, 3, 4, 5] as $day) {
            $this->book(sprintf('2026-09-%02d', $day), 'Guest '.$day);
        }

        $first = $this->json('GET', '/api/reservations?page=1&perPage=2');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $first['page']);
        self::assertSame(2, $first['perPage']);
        self::assertSame(5, $first['total']);
        self::assertTrue($first['hasMore']);
        self::assertSame(['Guest 1', 'Guest 2'], array_column($first['events'], 'title'));

        $last = $this->json('GET', '/api/reservations?page=3&perPage=2');
        self::assertSame(['Guest 5'], array_column($last['events'], 'title'));
        self::assertFalse($last['hasMore'], 'The third page of five bookings is the last one.');
    }

    public function testPagesDoNotOverlapOrSkipABooking(): void
    {
        // Two on one day, so the second page starts mid-day.
        $this->book('2026-09-01', 'First');
        $this->book('2026-09-01', 'Second');
        $this->book('2026-09-02', 'Third');

        $titles = [];

        for ($page = 1; $page <= 2; $page += 1) {
            $response = $this->json('GET', sprintf('/api/reservations?page=%d&perPage=2', $page));
            $titles = [...$titles, ...array_column($response['events'], 'title')];
        }

        self::assertSame(['First', 'Second', 'Third'], $titles);
    }

    public function testWithoutARangeTheWholeCalendarIsPaged(): void
    {
        $this->book('2016-02-16', 'Millicent Convento');
        $this->book('2026-09-11', 'Ada Lovelace');
        $this->book('2030-01-01', 'Grace Hopper');

        $page = $this->json('GET', '/api/reservations?page=1&perPage=10');

        self::assertSame(3, $page['total']);
        self::assertSame(
            ['Millicent Convento', 'Ada Lovelace', 'Grace Hopper'],
            array_column($page['events'], 'title'),
            'Without a range, bookings come back in date order from the beginning of the table.',
        );
    }

    public function testAPagePastTheEndIsEmptyRatherThanAnError(): void
    {
        $this->book('2026-09-11', 'Ada Lovelace');

        $page = $this->json('GET', '/api/reservations?page=99&perPage=10');

        self::assertResponseIsSuccessful();
        self::assertSame([], $page['events']);
        self::assertFalse($page['hasMore']);
        self::assertSame(1, $page['total']);
    }

    public function testAPageSizeBeyondTheCeilingIsRejected(): void
    {
        $this->json('GET', '/api/reservations?perPage='.(ReservationPageQuery::MAX_PER_PAGE + 1));

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnImpossiblePageNumberIsRejected(): void
    {
        $this->json('GET', '/api/reservations?page=0');

        self::assertResponseStatusCodeSame(400);
    }
}
