<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Decides what a visitor without a session gets.
 *
 * A browser asking for a page is sent to the sign-in form. The calendar's
 * JavaScript asks for `application/json` instead, and a redirect would hand it
 * the login page's HTML under a 200 status - which the fetch client would
 * report as "request failed with status 200", the least useful sentence in the
 * application. Those callers get a 401 problem+json instead, matching the error
 * shape every other API failure uses.
 */
final readonly class SessionEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_contains($request->headers->get('Accept') ?? '', 'application/json')) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Unauthorized',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Your session has expired. Reload the page to sign in again.',
            ], Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/problem+json']);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
