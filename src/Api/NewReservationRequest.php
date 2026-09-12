<?php

declare(strict_types=1);

namespace App\Api;

use App\Booking\DayTime;
use App\Booking\ReservationInput;
use App\Entity\Customer;
use App\Entity\Reservation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON body of `POST /api/reservations`.
 *
 * A name and a phone number are required; the email, the time and the notes are
 * not.
 */
final readonly class NewReservationRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'A date is required.')]
        #[Assert\Date(message: 'The date must use the YYYY-MM-DD format.')]
        public string $date = '',

        #[Assert\NotBlank(message: "The customer's name is required.", normalizer: 'trim')]
        #[Assert\Length(max: Customer::NAME_MAX_LENGTH)]
        public string $customerName = '',

        #[Assert\NotBlank(message: "The customer's phone number is required.", normalizer: 'trim')]
        #[Assert\Length(max: Customer::PHONE_MAX_LENGTH)]
        #[Assert\Regex(pattern: ContactConstraints::PHONE_PATTERN, message: ContactConstraints::PHONE_MESSAGE)]
        public string $customerPhone = '',

        #[Assert\Email(message: 'The email address is not valid.')]
        #[Assert\Length(max: Customer::EMAIL_MAX_LENGTH)]
        public ?string $customerEmail = null,

        #[Assert\Regex(pattern: DayTime::PATTERN, message: ContactConstraints::TIME_MESSAGE)]
        public ?string $startsAt = null,

        #[Assert\Length(max: Reservation::DETAILS_MAX_LENGTH)]
        public ?string $details = null,

        /**
         * Set by the customer picker when an existing profile was chosen.
         * Absent means "resolve by phone, email or name" - see
         * App\Booking\CustomerResolver.
         */
        #[Assert\Positive]
        public ?int $customerId = null,
    ) {
    }

    public function day(): \DateTimeImmutable
    {
        return LocalDate::fromString($this->date);
    }

    public function input(): ReservationInput
    {
        return new ReservationInput(
            $this->customerName,
            $this->customerPhone,
            $this->customerEmail,
            DayTime::fromString($this->startsAt),
            $this->details,
            $this->customerId,
        );
    }
}
