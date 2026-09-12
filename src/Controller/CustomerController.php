<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\CustomerForm;
use App\Api\CustomerPresenter;
use App\Api\FormViolations;
use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The customer directory: who the calendar books for, and everything about
 * them.
 *
 * This is the other half of putting a customer on a booking. Without it the
 * profiles would exist but nobody could look one up, correct a phone number, or
 * answer "when was Ada last in?" - which is the point of keeping them.
 *
 * The profile form is the whole truth: a blank field clears it. A booking form
 * is the opposite (see App\Entity\Customer::absorb), because an operator taking
 * a booking may simply not know the email address, and that is not an
 * instruction to forget it.
 */
final class CustomerController extends AbstractController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ReservationRepository $reservations,
        private readonly FormViolations $violations,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly int $customersPerPage,
    ) {
    }

    #[Route('/customers', name: 'app_customers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query->all()['q'] ?? null;
        $query = is_string($query) ? trim($query) : '';

        // An empty query is not a special case: it is the whole directory,
        // filtered by nothing, and it pages the same way a search does.
        $total = $this->customers->countDirectory($query);
        $pageCount = max(1, (int) ceil($total / $this->customersPerPage));

        // A stale link to page 7 of 3 lists page 3, not an empty table.
        $page = min($pageCount, max(1, $this->requestedPage($request)));

        $customers = $this->customers->findDirectoryPage(
            $query,
            $this->clock->now()->setTime(0, 0),
            ($page - 1) * $this->customersPerPage,
            $this->customersPerPage,
        );

        $context = [
            'customers' => $customers,
            'counts' => $this->customers->bookingCounts($this->ids($customers)),
            'query' => $query,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ];

        // The page's own script asks for just the card when it filters or pages
        // in place; a link or a form followed without JavaScript gets the whole
        // page and the card inside it.
        return $request->isXmlHttpRequest()
            ? $this->render('customer/_results.html.twig', $context)
            : $this->render('customer/index.html.twig', $context);
    }

    #[Route('/customers/{id<\d+>}', name: 'app_customer', methods: ['GET', 'POST'])]
    public function show(Customer $customer, Request $request): Response
    {
        return $request->isMethod(Request::METHOD_POST)
            ? $this->save($customer, $request)
            : $this->profile($customer);
    }

    private function save(Customer $customer, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('customer-'.$customer->getId(), $request->request->getString('_csrf_token'))) {
            return $this->profile(
                $customer,
                ['_' => 'Your session expired. Please submit the form again.'],
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        $form = CustomerForm::fromRequest($request);
        $errors = $this->violations->flatten($form);

        if ($errors !== []) {
            // Nothing is written, so the stored profile is unchanged and the
            // rejected values exist only in the form on screen.
            return $this->profile($customer, $errors, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $customer->reviseProfile($form->fullName, $form->phone, $form->email, $form->notes);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Saved the profile for %s.', $customer->getFullName()));

        return $this->redirectToRoute('app_customer', ['id' => $customer->getId()]);
    }

    /**
     * @param array<string, string> $errors
     */
    private function profile(
        Customer $customer,
        array $errors = [],
        ?CustomerForm $form = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $bookings = $this->reservations->findForCustomer($customer);
        $today = $this->clock->now()->setTime(0, 0);

        return $this->render('customer/show.html.twig', [
            'customer' => $customer,
            'bookings' => array_map(CustomerPresenter::booking(...), $bookings),
            'upcoming_count' => count(array_filter(
                $bookings,
                static fn ($booking): bool => $booking->getReservedOn() >= $today,
            )),
            'errors' => $errors,
            'form' => $form,
        ], new Response(status: $status));
    }

    /**
     * @param list<Customer> $customers
     *
     * @return list<int>
     */
    private function ids(array $customers): array
    {
        $ids = [];

        foreach ($customers as $customer) {
            if ($customer->getId() !== null) {
                $ids[] = $customer->getId();
            }
        }

        return $ids;
    }

    private function requestedPage(Request $request): int
    {
        $requested = $request->query->all()['page'] ?? null;

        return is_numeric($requested) ? (int) $requested : 1;
    }
}
