<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;

/**
 * A customer found for a booking, and the detail that identified them.
 *
 * The distinction exists because the details are not equally trustworthy. A
 * phone number or an email address belongs to one person; a name belongs to
 * everyone who shares it. A booking that matched on a name may well be the same
 * person - but it may equally be a different person with the same name, or a
 * typo in a phone number that sent the lookup down the name path, and neither
 * is a good reason to overwrite a stored contact detail.
 */
final readonly class CustomerMatch
{
    public const string BY_PHONE = 'phone';
    public const string BY_EMAIL = 'email';
    public const string BY_NAME = 'name';

    public function __construct(
        public Customer $customer,
        public string $identifiedBy,
    ) {
    }

    /**
     * Whether the match is strong enough to let a booking form update the
     * stored details. False for a name-only match.
     */
    public function isDefinite(): bool
    {
        return $this->identifiedBy !== self::BY_NAME;
    }
}
