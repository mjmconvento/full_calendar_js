<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * The page is the whole UI, so its render is worth a smoke test: it catches a
 * broken Twig template, a missing asset entrypoint or an unroutable API path.
 */
final class CalendarPageTest extends ApiTestCase
{
    public function testCalendarPageExposesItsApiEndpoints(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $mount = $crawler->filter('#calendar');
        self::assertCount(1, $mount, 'The calendar mount point must be rendered.');
        self::assertSame('/api/reservations', $mount->attr('data-reservations-url'));
        self::assertSame('10', $mount->attr('data-bookings-per-page'));
        self::assertMatchesRegularExpression(
            '#^/day/\d{4}-\d{2}-\d{2}$#',
            (string) $mount->attr('data-day-url'),
            'The script swaps the last path segment for the date it wants.',
        );
    }

    public function testCalendarPageLoadsTheCompiledAssetEntrypoint(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertGreaterThan(
            0,
            $crawler->filter('script[type="importmap"]')->count(),
            'AssetMapper must render an importmap for the app entrypoint.',
        );
    }

    /**
     * The calendar page hands the day view the day it is focused on, so that
     * "open this month" links land somewhere sensible.
     */
    public function testCalendarOpensOnTheRequestedDay(): void
    {
        $crawler = $this->client->request('GET', '/?day=2026-11-20');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-11-20', $crawler->filter('#calendar')->attr('data-focus-date'));
    }

    public function testCalendarIgnoresADayThatIsNotADay(): void
    {
        $crawler = $this->client->request('GET', '/?day=2026-02-30');

        self::assertResponseIsSuccessful();
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $crawler->filter('#calendar')->attr('data-focus-date'),
            'The thirtieth of February is not a date; the calendar falls back to today rather than moving the month.',
        );
    }

    public function testTheCalendarIsNotPublic(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }
}
