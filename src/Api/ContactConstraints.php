<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Validation rules shared by every surface that accepts customer details, so
 * the JSON API and the day page's plain HTML form cannot drift apart.
 */
final class ContactConstraints
{
    /**
     * Digits plus the punctuation a written phone number may contain:
     * "+63 917-555-0134", "(02) 8555 0100" and similar all pass.
     */
    public const string PHONE_PATTERN = '/^\+?[0-9][0-9\s().\-]{4,}$/';

    public const string PHONE_MESSAGE = 'The phone number may only contain digits, spaces and + ( ) . - characters.';

    public const string TIME_MESSAGE = 'The time must use the 24-hour HH:MM format, for example 18:30.';

    private function __construct()
    {
    }
}
