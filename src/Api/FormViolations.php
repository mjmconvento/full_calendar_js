<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns a validator run into the shape a template can render.
 *
 * Both HTML forms in the application - the day page's booking forms and the
 * sign-up form - need "one message per field", and neither wants to walk the
 * violation list itself. Binding values *into* a form object is deliberately
 * not here: each form does that with explicit, typed assignments, so no
 * property name is ever written through a string.
 */
final readonly class FormViolations
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Field name to the first message about it. Inputs with no message of their
     * own are simply absent, and a violation raised on the form as a whole
     * lands under the empty key, which templates render as a banner.
     *
     * @return array<string, string>
     */
    public function flatten(object $form): array
    {
        $errors = [];

        foreach ($this->validator->validate($form) as $violation) {
            $field = (string) $violation->getPropertyPath();

            if (!isset($errors[$field])) {
                $errors[$field] = (string) $violation->getMessage();
            }
        }

        return $errors;
    }
}
