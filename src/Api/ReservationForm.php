<?php

declare(strict_types=1);

namespace App\Api;

use App\Booking\DayTime;
use App\Booking\ReservationInput;
use App\Entity\Customer;
use App\Entity\Reservation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The plain HTML booking form on a day's page.
 *
 * The calendar's dialogs post JSON to the API, but a page that works without
 * JavaScript - and without a fetch client - is worth having for the one view an
 * operator uses all day. Symfony's validator applies the same rules the API
 * uses, so the two surfaces cannot disagree about what a valid booking is.
 *
 * No `date` field: the day comes from the URL, never from the form. And no
 * customer search here either - the picker needs JavaScript, so this form stays
 * the fallback that simply types a name and phone number and lets the resolver
 * match the profile.
 */
final class ReservationForm
{
    #[Assert\NotBlank(message: "The customer's name is required.", normalizer: 'trim')]
    #[Assert\Length(max: Customer::NAME_MAX_LENGTH)]
    public string $customerName = '';

    #[Assert\NotBlank(message: "The customer's phone number is required.", normalizer: 'trim')]
    #[Assert\Length(max: Customer::PHONE_MAX_LENGTH)]
    #[Assert\Regex(pattern: ContactConstraints::PHONE_PATTERN, message: ContactConstraints::PHONE_MESSAGE)]
    public string $customerPhone = '';

    #[Assert\Email(message: 'The email address is not valid.')]
    #[Assert\Length(max: Customer::EMAIL_MAX_LENGTH)]
    public ?string $customerEmail = null;

    #[Assert\Regex(pattern: DayTime::PATTERN, message: ContactConstraints::TIME_MESSAGE)]
    public ?string $startsAt = null;

    #[Assert\Length(max: Reservation::DETAILS_MAX_LENGTH)]
    public ?string $details = null;

    /**
     * The profile the picker chose, when it was used. Null means the resolver
     * matches on phone, email or name.
     */
    #[Assert\Positive]
    public ?int $customerId = null;

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

    /**
     * Reads the submitted form. Every field is explicit, so a request carrying
     * `customerName[]=` or a nested object cannot reach a string-typed property.
     */
    public static function fromRequest(Request $request): self
    {
        $form = new self();
        $form->customerName = self::string($request, 'customerName');
        $form->customerPhone = self::string($request, 'customerPhone');
        $form->customerEmail = self::nullableString($request, 'customerEmail');
        $form->startsAt = self::nullableString($request, 'startsAt');
        $form->details = self::nullableString($request, 'details');
        $form->customerId = self::nullableInt($request, 'customerId');

        return $form;
    }

    private static function string(Request $request, string $field): string
    {
        $value = $request->request->all()[$field] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(Request $request, string $field): ?string
    {
        $value = self::string($request, $field);

        return $value === '' ? null : $value;
    }

    private static function nullableInt(Request $request, string $field): ?int
    {
        $value = self::string($request, $field);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
