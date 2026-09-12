<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Customer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The customer profile form.
 *
 * Distinct from the booking form on purpose: this one is the whole record, so a
 * blank optional field clears it. The name and the phone number are required on
 * both, and cannot be blanked.
 */
final class CustomerForm
{
    #[Assert\NotBlank(message: "The customer's name is required.", normalizer: 'trim')]
    #[Assert\Length(max: Customer::NAME_MAX_LENGTH)]
    public string $fullName = '';

    #[Assert\NotBlank(message: "The customer's phone number is required.", normalizer: 'trim')]
    #[Assert\Length(max: Customer::PHONE_MAX_LENGTH)]
    #[Assert\Regex(pattern: ContactConstraints::PHONE_PATTERN, message: ContactConstraints::PHONE_MESSAGE)]
    public string $phone = '';

    #[Assert\Email(message: 'The email address is not valid.')]
    #[Assert\Length(max: Customer::EMAIL_MAX_LENGTH)]
    public ?string $email = null;

    #[Assert\Length(max: Customer::NOTES_MAX_LENGTH)]
    public ?string $notes = null;

    public function __construct(
        string $fullName = '',
        string $phone = '',
        ?string $email = null,
        ?string $notes = null,
    ) {
        $this->fullName = $fullName;
        $this->phone = $phone;
        $this->email = $email;
        $this->notes = $notes;
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::value($request, 'fullName'),
            self::value($request, 'phone'),
            self::nullable($request, 'email'),
            self::nullable($request, 'notes'),
        );
    }

    /**
     * Prefills the form from the stored profile.
     */
    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            $customer->getFullName(),
            $customer->getPhone(),
            $customer->getEmail(),
            $customer->getNotes(),
        );
    }

    private static function value(Request $request, string $field): string
    {
        $value = $request->request->all()[$field] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullable(Request $request, string $field): ?string
    {
        $value = self::value($request, $field);

        return $value === '' ? null : $value;
    }
}
