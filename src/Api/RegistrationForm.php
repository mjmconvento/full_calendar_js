<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The sign-up form's fields, validated by the same validator the API DTOs use.
 *
 * The confirmation field is not a column: it exists so a mistyped password is
 * caught before the account exists, and an account whose password nobody knows
 * is one only an operator could repair.
 */
#[Assert\Callback('assertPasswordsMatch')]
final class RegistrationForm
{
    public const int PASSWORD_MIN_LENGTH = 8;

    /**
     * Strict (RFC) mode is the same check the mailer applies when it builds
     * the recipient address, so an address the form accepts is one the
     * verification email can be sent to.
     */
    #[Assert\NotBlank(message: 'An email address is required.', normalizer: 'trim')]
    #[Assert\Email(message: 'The email address is not valid.', mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: User::EMAIL_MAX_LENGTH)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Your first name is required.', normalizer: 'trim')]
    #[Assert\Length(max: User::NAME_MAX_LENGTH)]
    public string $firstName = '';

    /**
     * Optional: not everyone has one, and a form that insists would turn
     * those people away at the door.
     */
    #[Assert\Length(max: User::NAME_MAX_LENGTH)]
    public string $middleName = '';

    #[Assert\NotBlank(message: 'Your last name is required.', normalizer: 'trim')]
    #[Assert\Length(max: User::NAME_MAX_LENGTH)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'A password is required.')]
    #[Assert\Length(
        min: self::PASSWORD_MIN_LENGTH,
        max: User::PASSWORD_MAX_LENGTH,
        minMessage: 'Use at least {{ limit }} characters.',
    )]
    public string $password = '';

    #[Assert\NotBlank(message: 'Repeat the password.')]
    public string $passwordConfirmation = '';

    public function assertPasswordsMatch(ExecutionContextInterface $context): void
    {
        if ($this->password !== $this->passwordConfirmation) {
            $context->buildViolation('The two passwords do not match.')
                ->atPath('passwordConfirmation')
                ->addViolation();
        }
    }

    /**
     * Reads the submitted form. Explicit per field, so a malformed request
     * cannot put a non-string where a string is expected.
     */
    public static function fromRequest(Request $request): self
    {
        $form = new self();
        $form->email = self::value($request, 'email');
        $form->firstName = self::value($request, 'firstName');
        $form->middleName = self::value($request, 'middleName');
        $form->lastName = self::value($request, 'lastName');
        $form->password = self::value($request, 'password');
        $form->passwordConfirmation = self::value($request, 'passwordConfirmation');

        return $form;
    }

    private static function value(Request $request, string $field): string
    {
        $value = $request->request->all()[$field] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
