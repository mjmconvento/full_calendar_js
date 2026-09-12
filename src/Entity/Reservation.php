<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One booking for a single calendar day, for one customer.
 *
 * A day takes as many bookings as it is given: there is no per-day cap and no
 * numbered slot. The 2016 application capped a day at two in JavaScript, and
 * for a while this schema expressed that cap as a unique (reserved_on, slot)
 * index - which is why `slot` used to exist. With no cap there is nothing for a
 * slot to constrain, so it is gone along with the two "the day moved under you"
 * exceptions it needed.
 *
 * The customer's name, phone and email live on App\Entity\Customer, and this row
 * points at it. What belongs to the booking itself - the day, the time agreed
 * for, and the notes - stays here.
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(name: 'idx_reservation_reserved_on', columns: ['reserved_on'])]
#[ORM\Index(name: 'idx_reservation_customer', columns: ['customer_id'])]
class Reservation
{
    /** Length of the booking's own free-text notes. */
    public const int DETAILS_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $reservedOn;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Customer $customer,
        \DateTimeImmutable $reservedOn,
        ?\DateTimeImmutable $startsAt = null,
        ?string $details = null,
        ?\DateTimeImmutable $now = null,
    ) {
        $now ??= new \DateTimeImmutable();

        $this->customer = $customer;
        $this->reservedOn = $reservedOn->setTime(0, 0);
        $this->startsAt = $startsAt;
        $this->details = self::normalize($details);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getCustomerName(): string
    {
        return $this->customer->getFullName();
    }

    public function getCustomerPhone(): string
    {
        return $this->customer->getPhone();
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customer->getEmail();
    }

    public function getReservedOn(): \DateTimeImmutable
    {
        return $this->reservedOn;
    }

    /**
     * Preferred time of day, or null when the customer did not name one.
     * The date part is always the Unix epoch; only the clock part is meaningful.
     */
    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The time as the day page and the dashboard print it - "6:30 PM" - or null
     * when no time was agreed.
     */
    public function getFormattedStartsAt(): ?string
    {
        return $this->startsAt?->format('g:i A');
    }

    /**
     * Moves the booking to another customer, keeping its day.
     */
    public function assignTo(Customer $customer, ?\DateTimeImmutable $now = null): void
    {
        $this->customer = $customer;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    /**
     * Replaces what belongs to the booking rather than to the person.
     *
     * The day stays immutable: moving a booking means cancelling it and booking
     * the target day, so the two ends of the move are both recorded.
     */
    public function reviseDetails(?\DateTimeImmutable $startsAt, ?string $details, ?\DateTimeImmutable $now = null): void
    {
        $this->startsAt = $startsAt;
        $this->details = self::normalize($details);
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    private static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
