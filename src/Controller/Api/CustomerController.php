<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\CustomerPresenter;
use App\Repository\CustomerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer lookup for the booking forms.
 *
 * The picker asks this on every keystroke, so the response is deliberately
 * small and the result count is bounded by the repository rather than by the
 * caller: an operator searching for "a" gets the ten most relevant people, not
 * everyone whose name contains the letter.
 *
 * An empty query lists the people most recently dealt with, which is what the
 * dropdown shows before anything is typed.
 */
#[Route('/api/customers', name: 'api_customers_')]
final class CustomerController extends AbstractController
{
    /**
     * Enough to fill a dropdown; more than fits on screen without scrolling.
     */
    private const int MAX_RESULTS = 20;

    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $request->query->all()['q'] ?? null;

        $matches = $this->customers->search(
            is_string($query) ? $query : null,
            self::MAX_RESULTS,
        );

        return new JsonResponse([
            'customers' => CustomerPresenter::summaries($matches),
        ], Response::HTTP_OK);
    }
}
