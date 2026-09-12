<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * Everything one request says about a booking, frozen once validated.
 *
 * Both mutation paths - the JSON API behind the calendar and the plain HTML
 * forms - resolve their request into this object, so the booking service never
 * has to know which surface called it.
 *
 * A name and a phone number are required; the email and the time are not.
 * `customerId` is the "this is an existing customer" signal the search box
 * sets; when it is absent the resolver looks the person up by phone, email or
 * name before falling back to creating a profile. See App\Booking\CustomerResolver.
 */
final readonly class ReservationInput
{
    public string $customerName;
    public string $customerPhone;
    public ?string $customerEmail;
    public ?\DateTimeImmutable $startsAt;
    public ?string $details;

    public function __construct(
        string $customerName,
        string $customerPhone,
        ?string $customerEmail = null,
        ?\DateTimeImmutable $startsAt = null,
        ?string $details = null,
        public ?int $customerId = null,
    ) {
        $this->customerName = trim($customerName);
        $this->customerPhone = trim($customerPhone);
        $this->customerEmail = self::normalize($customerEmail);
        $this->startsAt = $startsAt;
        $this->details = self::normalize($details);
    }

    private static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
