<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\DashboardMetrics;
use App\Booking\ReservationBooker;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The screen an operator opens in the morning: how much is on the books, and
 * every booking on file.
 *
 * The list is paged in SQL rather than assembled in PHP. The legacy app dumped
 * every row of `calendar` into the page on each request, which was fine for the
 * handful of demo rows in the original SQL dump and would not be for a real one.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationBooker $booker,
        private readonly DashboardMetrics $metrics,
        private readonly int $bookingsPerPage,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $stats = $this->metrics->current();
        $total = $stats->totalBooked;
        $pageCount = max(1, (int) ceil($total / $this->bookingsPerPage));

        // A stale link to page 7 of 3 lists page 3, not an empty table.
        $page = min($pageCount, max(1, $this->requestedPage($request)));

        $bookings = [
            'reservations' => $this->reservations->findPage(($page - 1) * $this->bookingsPerPage, $this->bookingsPerPage),
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
        ];

        // The page's own script asks for just the list when it moves between
        // pages; a link followed without JavaScript gets the whole page.
        if ($request->isXmlHttpRequest()) {
            return $this->render('dashboard/_bookings.html.twig', $bookings);
        }

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
            'today_schedule' => $this->booker->scheduleOn($stats->today),
        ] + $bookings);
    }

    private function requestedPage(Request $request): int
    {
        $requested = $request->query->all()['page'] ?? null;

        return is_numeric($requested) ? (int) $requested : 1;
    }
}
