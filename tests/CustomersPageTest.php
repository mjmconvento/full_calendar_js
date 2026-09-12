<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Customer;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The directory's two promises: it is ordered by how recently the operation
 * last dealt with each customer, and a search pages the same way the whole
 * directory does - a page of matches, never a page of everything.
 */
final class CustomersPageTest extends ApiTestCase
{
    public function testTheCustomerBookedClosestToTodayComesFirst(): void
    {
        $today = new \DateTimeImmutable('today');

        $this->book($today->format('Y-m-d'), 'Barbara Liskov');
        $this->book($today->modify('-10 days')->format('Y-m-d'), 'Millicent Convento');

        $crawler = $this->client->request('GET', '/customers');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Barbara Liskov', 'Millicent Convento'],
            $this->listedNames($crawler),
        );
    }

    public function testAFutureBookingDoesNotMoveACustomerUpTheDirectory(): void
    {
        $today = new \DateTimeImmutable('today');

        $this->book($today->modify('-1 day')->format('Y-m-d'), 'Ada Lovelace');
        $this->book($today->modify('+1 day')->format('Y-m-d'), 'Grace Hopper');

        $crawler = $this->client->request('GET', '/customers');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Ada Lovelace', 'Grace Hopper'],
            $this->listedNames($crawler),
            'Grace is booked tomorrow, but a booking still to come says nothing about how recently she was dealt with.',
        );
    }

    public function testCustomersWithNoBookingAtOrBeforeTodayComeLastInNameOrder(): void
    {
        $today = new \DateTimeImmutable('today');

        $this->book($today->modify('-2 days')->format('Y-m-d'), 'Barbara Liskov');
        $this->entityManager->persist(new Customer('Alan Turing', '+63 917 555 0700'));
        $this->entityManager->persist(new Customer('Zoe Quinn', '+63 917 555 0701'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/customers');

        self::assertSame(
            ['Barbara Liskov', 'Alan Turing', 'Zoe Quinn'],
            $this->listedNames($crawler),
        );
    }

    public function testASearchPagesThroughMatchesAndKeepsTheQueryOnTheLinks(): void
    {
        for ($number = 1; $number <= 26; $number += 1) {
            $this->entityManager->persist(new Customer(
                sprintf('Ada Tester %02d', $number),
                sprintf('+63 917 555 %04d', 1000 + $number),
            ));
        }

        $this->entityManager->persist(new Customer('Grace Hopper', '+63 917 555 0099'));
        $this->entityManager->flush();

        $first = $this->client->request('GET', '/customers?q=ada');

        self::assertResponseIsSuccessful();
        self::assertCount(25, $first->filter('#customer-results tbody tr'), 'A directory page holds 25 people.');
        self::assertSame('Ada Tester 01', $this->listedNames($first)[0]);

        $links = $first->filter('#customer-results .pagination a.page-link')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertContains('/customers?q=ada&page=2', $links, 'Paging a search must not drop the term.');

        $second = $this->client->request('GET', '/customers?q=ada&page=2');

        self::assertResponseIsSuccessful();
        self::assertSame(['Ada Tester 26'], $this->listedNames($second));
    }

    public function testAFragmentRequestAnswersWithJustTheCard(): void
    {
        $this->client->request('GET', '/customers', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('app-bar', $content, 'A fragment response must not carry the page around it.');
        self::assertStringNotContainsString('<main', $content);
        self::assertStringContainsString('All customers', $content);
    }

    /**
     * @return list<string>
     */
    private function listedNames(Crawler $crawler): array
    {
        return $crawler->filter('#customer-results tbody tr td:first-child a.row-title')
            ->each(static fn (Crawler $link): string => trim($link->text()));
    }
}
