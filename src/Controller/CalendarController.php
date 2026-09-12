<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\LocalDate;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the single page of the app: the booking calendar itself.
 */
final class CalendarController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $bookingsPerPage,
    ) {
    }

    /**
     * `?day=YYYY-MM-DD` (or `?date=` from the original query string) opens the
     * calendar on the month holding that day and highlights it, so
     * /day/{date} can send a visitor back to the calendar with the right day
     * already in view.
     */
    #[Route('/', name: 'calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = $this->clock->now()->setTime(0, 0);
        $focus = $this->requestedDay($request, 'day') ?? $this->requestedDay($request, 'date') ?? $today;

        return $this->render('calendar/index.html.twig', [
            'bookings_per_page' => $this->bookingsPerPage,
            'today' => $today->format(LocalDate::FORMAT),
            'focus_date' => $focus->format(LocalDate::FORMAT),
        ]);
    }

    private function requestedDay(Request $request, string $parameter): ?\DateTimeImmutable
    {
        $requested = $request->query->all()[$parameter] ?? null;

        return is_string($requested) ? LocalDate::tryFromString($requested) : null;
    }
}
