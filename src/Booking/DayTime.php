<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * Parses the `HH:MM` strings the API and the day page speak for a booking's
 * preferred time of day.
 *
 * A reservation stores a DATE plus a TIME, so this helper keeps only the clock
 * part: the returned value always carries the Unix epoch as its date, the same
 * value Doctrine's TIME_IMMUTABLE type reconstructs when it reads a row. That
 * way an in-memory reservation and a freshly loaded one format alike.
 *
 * The hour is range-checked here rather than left to the parser. PHP happily
 * reads "25:00" as one in the morning the next day, which would quietly move a
 * customer's booking instead of rejecting it.
 */
final class DayTime
{
    public const string FORMAT = 'H:i';

    /** Matches "9:30" through "23:59"; anything else is not a time of day. */
    public const string PATTERN = '/^([01]?\d|2[0-3]):([0-5]\d)$/';

    private function __construct()
    {
    }

    public static function fromString(?string $time): ?\DateTimeImmutable
    {
        $time = $time === null ? null : trim($time);

        if ($time === null || $time === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $time, $parts) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid %s time.', $time, self::FORMAT));
        }

        // The leading "!" resets every unparsed field, so the date lands on the
        // epoch; the hour is left-padded so the parser sees the canonical form.
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, sprintf('%02d:%02d', (int) $parts[1], (int) $parts[2]));

        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid %s time.', $time, self::FORMAT));
        }

        return $parsed;
    }

    public static function toString(?\DateTimeImmutable $time): ?string
    {
        return $time?->format(self::FORMAT);
    }
}
