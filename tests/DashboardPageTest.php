<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The dashboard list is a ledger, and a ledger reads newest first: the most
 * recent booking is the one the operator is normally looking for.
 */
final class DashboardPageTest extends ApiTestCase
{
    public function testTheBookingsListRunsLatestFirst(): void
    {
        $this->book('2016-02-16', 'Millicent Convento');
        $this->book('2026-01-05', 'Ada Lovelace');
        $this->book('2030-01-01', 'Grace Hopper');

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$this->dayLabel('2030-01-01'), $this->dayLabel('2026-01-05'), $this->dayLabel('2016-02-16')],
            $this->listedDates($crawler),
        );
    }

    public function testTheListPagesWithTheNewestBookingOnTheFirstPage(): void
    {
        for ($day = 1; $day <= 11; $day += 1) {
            $this->book(sprintf('2026-01-%02d', $day), sprintf('Guest %02d', $day));
        }

        $first = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(10, $first->filter('#bookings-results tbody tr'), 'A bookings page holds ten rows.');
        self::assertSame($this->dayLabel('2026-01-11'), $this->listedDates($first)[0]);

        $second = $this->client->request('GET', '/dashboard?page=2');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$this->dayLabel('2026-01-01')],
            $this->listedDates($second),
            'The oldest booking is on the last page.',
        );
    }

    public function testAFragmentRequestAnswersWithJustTheList(): void
    {
        $this->book('2026-01-05', 'Ada Lovelace');

        $this->client->request('GET', '/dashboard', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('app-bar', $content, 'A fragment response must not carry the page around it.');
        self::assertStringContainsString('All bookings', $content);
    }

    /**
     * @return list<string>
     */
    private function listedDates(Crawler $crawler): array
    {
        return $crawler->filter('#bookings-results tbody tr td:first-child a.row-title')
            ->each(static fn (Crawler $link): string => trim($link->text()));
    }

    private function dayLabel(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('D, j M Y');
    }
}
