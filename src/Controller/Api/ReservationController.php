<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\NewReservationRequest;
use App\Api\ReservationPage;
use App\Api\ReservationPageQuery;
use App\Api\ReservationPresenter;
use App\Api\ReviseReservationRequest;
use App\Booking\ReservationBooker;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API behind the calendar. Replaces the legacy `process.php`, whose single
 * endpoint switched on a POST field named `type` and interpolated raw request
 * data into SQL.
 *
 * Failures are rendered by Symfony as `application/problem+json`:
 * 400 for a malformed query string, 404 for an unknown reservation, 422 for an
 * invalid JSON body. There is no 409 any more: it reported a day that had
 * reached its cap, and days are no longer capped.
 *
 * Everything here requires a signed-in account; a request without a session
 * gets 401 problem+json rather than the sign-in page's HTML (see
 * App\Security\SessionEntryPoint).
 */
#[Route('/api/reservations', name: 'api_reservations_')]
final class ReservationController extends AbstractController
{
    public function __construct(
        private readonly ReservationBooker $booker,
        private readonly ReservationRepository $reservations,
    ) {
    }

    /**
     * Legacy equivalent: `type=fetch`, which needed six separate POST fields
     * and rebuilt the month window in JavaScript.
     *
     * Answers one page at a time - `{events, page, perPage, total, hasMore}` -
     * so a window holding hundreds of bookings is drawn as it arrives instead
     * of being shipped in one response.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        ReservationPageQuery $query,
    ): JsonResponse {
        $page = new ReservationPage(
            $this->reservations->findRangePage($query->from(), $query->until(), $query->offset(), $query->perPage),
            $query->page,
            $query->perPage,
            $this->reservations->countRange($query->from(), $query->until()),
        );

        return new JsonResponse($page->toArray());
    }

    /**
     * Legacy equivalent: `type=new`.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] NewReservationRequest $request): JsonResponse
    {
        $reservation = $this->booker->book($request->day(), $request->input());

        return new JsonResponse(ReservationPresenter::event($reservation), Response::HTTP_CREATED);
    }

    /**
     * Legacy equivalent: `type=edit`.
     */
    #[Route('/{id<\d+>}', name: 'revise', methods: ['PATCH'])]
    public function revise(
        Reservation $reservation,
        #[MapRequestPayload]
        ReviseReservationRequest $request,
    ): JsonResponse {
        $this->booker->revise($reservation, $request->input());

        return new JsonResponse(ReservationPresenter::event($reservation));
    }

    /**
     * Legacy equivalent: `type=remove`.
     */
    #[Route('/{id<\d+>}', name: 'cancel', methods: ['DELETE'])]
    public function cancel(Reservation $reservation): JsonResponse
    {
        $this->booker->cancel($reservation);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
