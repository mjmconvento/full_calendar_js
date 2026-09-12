<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\FormViolations;
use App\Api\LocalDate;
use App\Api\ReservationForm;
use App\Booking\ReservationBooker;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One day, in full: every booking with the customer's phone, email, preferred
 * time and notes, and a place to book or change one.
 *
 * The month grid is the right place to see the shape of a month and the wrong
 * place to read a customer's details: the whole month's labels compete for the
 * same few pixels. This page is where the detail lives, and the calendar links
 * to it rather than trying to show it inline.
 *
 * It is deliberately plain HTML - no fetch client, no dialogs. Every action is
 * a form post that redirects back to the day, so the page still works if a
 * script fails to load, which the calendar's dialog-driven flow cannot promise.
 *
 * Actions are addressed by an `intent` field on the POST:
 *   book    - new reservation on this day
 *   revise  - replace a booking's customer details
 *   cancel  - delete a booking
 * The legacy app switched on a single `type` field in process.php for the same
 * reason, but with one endpoint per action and a CSRF token per action here.
 */
final class DayController extends AbstractController
{
    public function __construct(
        private readonly ReservationBooker $booker,
        private readonly ReservationRepository $reservations,
        private readonly FormViolations $violations,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/day/{date}',
        name: 'app_day',
        methods: ['GET', 'POST'],
        requirements: ['date' => '\d{4}-\d{2}-\d{2}'],
    )]
    public function show(string $date, Request $request): Response
    {
        // Rejects both malformed strings and impossible dates: "2026-02-30"
        // matches the route requirement but is not a day.
        $day = LocalDate::tryFromString($date) ?? throw new NotFoundHttpException(
            sprintf('There is no such day as "%s".', $date),
        );

        return $request->isMethod(Request::METHOD_POST)
            ? $this->handleSubmission($day, $request)
            : $this->page($day);
    }

    private function handleSubmission(\DateTimeImmutable $day, Request $request): Response
    {
        $intent = $request->request->all()['intent'] ?? null;
        $reservation = $this->reservationOn($day, $request->request->all()['id'] ?? null);

        if ($intent === 'book') {
            if (!$this->isCsrfTokenValid($this->bookToken($day), $request->request->getString('_csrf_token'))) {
                return $this->stale($day);
            }

            return $this->book($day, $request);
        }

        if ($intent === 'revise' || $intent === 'cancel') {
            if ($reservation === null) {
                // The booking was cancelled in another tab, or the id belongs
                // to a different day than the one in the URL.
                return $this->page($day, ['_' => 'That booking is no longer on this day.']);
            }

            if (!$this->isCsrfTokenValid($intent.'-'.$reservation->getId(), $request->request->getString('_csrf_token'))) {
                return $this->stale($day);
            }

            return $intent === 'cancel'
                ? $this->cancel($day, $reservation)
                : $this->revise($day, $request, $reservation);
        }

        return $this->page($day, ['_' => 'That action is not available.'], status: Response::HTTP_BAD_REQUEST);
    }

    private function book(\DateTimeImmutable $day, Request $request): Response
    {
        $form = ReservationForm::fromRequest($request);
        $errors = $this->violations->flatten($form);

        if ($errors !== []) {
            return $this->page($day, $errors, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->booker->book($day, $form->input());

        $this->addFlash('success', sprintf('Booked %s on %s.', $form->customerName, $day->format(LocalDate::FORMAT)));

        return $this->redirectBack($day);
    }

    private function revise(\DateTimeImmutable $day, Request $request, Reservation $reservation): Response
    {
        $form = ReservationForm::fromRequest($request);
        $errors = $this->violations->flatten($form);

        if ($errors !== []) {
            // The row the error belongs to is reopened with what was typed.
            return $this->page($day, $errors, $form, $reservation->getId(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->booker->revise($reservation, $form->input());
        $this->addFlash('success', sprintf('Updated the booking for %s.', $form->customerName));

        return $this->redirectBack($day);
    }

    private function cancel(\DateTimeImmutable $day, Reservation $reservation): Response
    {
        $name = $reservation->getCustomerName();

        $this->booker->cancel($reservation);
        $this->addFlash('success', sprintf('Cancelled the booking for %s.', $name));

        return $this->redirectBack($day);
    }

    /**
     * Re-renders the day. `$errors` is keyed by form field; the empty key is a
     * message about the action as a whole rather than one input.
     *
     * @param array<string, string> $errors
     */
    private function page(
        \DateTimeImmutable $day,
        array $errors = [],
        ?ReservationForm $form = null,
        ?int $openEditId = null,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('calendar/day.html.twig', [
            'schedule' => $this->booker->scheduleOn($day),
            'today' => $this->clock->now()->setTime(0, 0)->format(LocalDate::FORMAT),
            'errors' => $errors,
            'form' => $form,
            'open_edit_id' => $openEditId,
            'book_token' => $this->bookToken($day),
        ], new Response(status: $status));
    }

    private function stale(\DateTimeImmutable $day): Response
    {
        return $this->page(
            $day,
            ['_' => 'Your session expired. Please submit the form again.'],
            status: Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * A submitted id is only honoured when the booking really is on the day in
     * the URL, so a hand-crafted form cannot edit another day's booking from
     * this page.
     */
    private function reservationOn(\DateTimeImmutable $day, mixed $id): ?Reservation
    {
        if (!is_numeric($id)) {
            return null;
        }

        $reservation = $this->reservations->find((int) $id);

        if ($reservation === null || $reservation->getReservedOn()->format(LocalDate::FORMAT) !== $day->format(LocalDate::FORMAT)) {
            return null;
        }

        return $reservation;
    }

    private function bookToken(\DateTimeImmutable $day): string
    {
        return 'book-'.$day->format(LocalDate::FORMAT);
    }

    private function redirectBack(\DateTimeImmutable $day): Response
    {
        return $this->redirectToRoute('app_day', ['date' => $day->format(LocalDate::FORMAT)]);
    }
}
