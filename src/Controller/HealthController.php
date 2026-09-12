<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The endpoint a hosting platform polls to decide whether this container is
 * alive (render.yaml: healthCheckPath).
 *
 * It answers through php-fpm rather than from nginx alone, so a dead PHP
 * process fails the check and gets the container replaced instead of quietly
 * 502-ing. It deliberately touches neither the database nor the session: the
 * platform calls it every few seconds, and a query here would keep a
 * scale-to-zero database awake around the clock and spend its monthly compute
 * allowance on nothing.
 */
final class HealthController
{
    #[Route('/healthz', name: 'app_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok', Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }
}
