<?php

declare(strict_types=1);

namespace App\Twig;

use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The two clock-derived values every template would otherwise be handed by its
 * controller.
 *
 * "Today" is needed by the navigation on every page, and a booking's preferred
 * time needs formatting on the dashboard, the day page and the calendar's
 * dialogs. Reading the clock here keeps the answer the same on every surface,
 * including the ones that never asked a controller for it.
 */
final class ClockExtension extends AbstractExtension
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('today', $this->today(...)),
        ];
    }

    /**
     * Midnight today, in the application's timezone.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
