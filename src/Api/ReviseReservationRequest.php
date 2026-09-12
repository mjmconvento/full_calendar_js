<?php

declare(strict_types=1);

namespace App\Api;

use App\Booking\DayTime;
use App\Booking\ReservationInput;
use App\Entity\Customer;
use App\Entity\Reservation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON body of `PATCH /api/reservations/{id}`.
 *
 * Every customer field is replaced wholesale, so omitting the optional ones
 * clears them - the same "the submitted form is the truth" rule the edit dialog
 * has always had. The phone number cannot be cleared: it is required.
 */
final readonly class ReviseReservationRequest
{
    public function __construct(
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
         * Names a different customer to move the booking to. Omitted - the
         * usual case - the booking keeps its customer and updates their profile
         * in place.
         */
        #[Assert\Positive]
        public ?int $customerId = null,
    ) {
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
