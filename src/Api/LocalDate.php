<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Parses the `YYYY-MM-DD` strings the API speaks. Validation constraints have
 * already rejected malformed input by the time these helpers run, so a failure
 * here is a programming error rather than a user error.
 */
final class LocalDate
{
    public const string FORMAT = 'Y-m-d';

    private function __construct()
    {
    }

    public static function fromString(string $date): \DateTimeImmutable
    {
        $parsed = self::tryFromString($date);

        if ($parsed === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid %s date.', $date, self::FORMAT));
        }

        return $parsed;
    }

    /**
     * Parses a date, or answers null when the string is malformed.
     *
     * The round-trip check is what makes this strict: PHP reads "2026-02-30" as
     * the second of March, so comparing the parsed value back against the input
     * rejects calendar dates that do not exist instead of silently shifting the
     * booking. The leading "!" in the format resets unparsed fields to midnight.
     */
    public static function tryFromString(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $date);

        if ($parsed === false || $parsed->format(self::FORMAT) !== $date) {
            return null;
        }

        return $parsed;
    }
}
