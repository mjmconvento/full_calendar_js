<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\CustomerMatch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Turns what a booking form submitted into the customer profile the booking
 * belongs to.
 *
 * This is what makes "search for an existing customer" and "just type a name"
 * the same operation from the booking service's point of view: whether the
 * operator picked someone from the list or typed a new person in, the booking
 * ends up pointing at exactly one profile.
 *
 * Three cases, in order:
 *   1. the form named a customer id - the picker was used, so trust it;
 *   2. the submitted phone number or email address matches a profile - a person,
 *      so the booking may also update their stored details;
 *   3. only the name matches - reuse the profile and leave it alone. Someone
 *      with the same name may be the same person, but a name is not evidence
 *      enough to overwrite a phone number with whatever was just typed.
 */
final readonly class CustomerResolver
{
    public function __construct(
        private CustomerRepository $customers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The profile a booking belongs to, created when this is a new person.
     * The caller is responsible for flushing the entity manager.
     */
    public function resolve(ReservationInput $input): Customer
    {
        $chosen = $this->byId($input->customerId);

        if ($chosen !== null) {
            // The picker named this profile, so the form's details describe
            // this person and may fill in whatever the profile was missing.
            $chosen->absorb($input, $this->clock->now());

            return $chosen;
        }

        $match = $this->customers->findByIdentity(
            $input->customerName,
            $input->customerPhone,
            $input->customerEmail,
        );

        if ($match instanceof CustomerMatch) {
            if ($match->isDefinite()) {
                $match->customer->absorb($input, $this->clock->now());
            }

            return $match->customer;
        }

        $customer = new Customer(
            $input->customerName,
            $input->customerPhone,
            $input->customerEmail,
            null,
            $this->clock->now(),
        );
        $this->entityManager->persist($customer);

        return $customer;
    }

    /**
     * The profile named explicitly by a form, or null when the id is unknown.
     */
    public function byId(?int $customerId): ?Customer
    {
        return $customerId === null ? null : $this->customers->find($customerId);
    }
}
