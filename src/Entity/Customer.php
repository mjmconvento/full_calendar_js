<?php

declare(strict_types=1);

namespace App\Entity;

use App\Booking\ReservationInput;
use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person the calendar books for, and the record their bookings point at.
 *
 * Before this existed, the customer was three columns repeated on every
 * reservation. That repeats the same person on every visit, and a typo fixed on
 * one booking leaves the others wrong. Here the person is one row, and a booking
 * refers to it.
 *
 * Name and phone are required; email and notes are not. The phone is the one
 * detail worth insisting on - it is how a booking is confirmed or moved when a
 * customer is not standing in front of you - and an email address is often not
 * known at the moment a booking is taken.
 *
 * A phone number on a row that predates this rule may still be the empty
 * string, written by the migration when it tightened the column; the validator
 * refuses to create or save such a customer from then on.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
#[ORM\Index(name: 'idx_customer_full_name', columns: ['full_name'])]
#[ORM\Index(name: 'idx_customer_email', columns: ['email'])]
#[ORM\Index(name: 'idx_customer_phone', columns: ['phone'])]
class Customer
{
    public const int NAME_MAX_LENGTH = 120;
    public const int PHONE_MAX_LENGTH = 40;
    public const int EMAIL_MAX_LENGTH = 180;
    public const int NOTES_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $fullName;

    #[ORM\Column(length: self::PHONE_MAX_LENGTH)]
    private string $phone;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH, nullable: true)]
    private ?string $email;

    /**
     * Anything the operator wants to remember about this person - allergies, a
     * preferred table, "always pays in cash". Free text, because the useful
     * fields are the ones nobody predicted.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'customer')]
    private Collection $reservations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $fullName,
        string $phone,
        ?string $email = null,
        ?string $notes = null,
        ?\DateTimeImmutable $now = null,
    ) {
        $now ??= new \DateTimeImmutable();

        $this->fullName = trim($fullName);
        $this->phone = trim($phone);
        $this->email = self::normalizeEmail($email);
        $this->notes = self::normalize($notes);
        $this->reservations = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
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
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    /**
     * The contact details there are to show, phone first. Used to label a
     * customer in a list without a template having to assemble the parts.
     */
    public function getContactSummary(): string
    {
        return implode(' · ', array_filter([$this->phone, $this->email]));
    }

    /**
     * Takes on whatever a booking form knew about this person.
     *
     * Merging rather than replacing is deliberate, and it is the opposite of
     * what reviseProfile() does. A booking form is filled in while the customer
     * is standing there: the operator may not have an email address, and that
     * blank means "not asked", not "delete what you have". Changing a stored
     * value is an edit to the profile, and the profile page is where that
     * happens.
     */
    public function absorb(ReservationInput $input, ?\DateTimeImmutable $now = null): void
    {
        $this->reviseProfile(
            $input->customerName,
            $input->customerPhone,
            $input->customerEmail ?? $this->email,
            $this->notes,
            $now,
        );
    }

    /**
     * Replaces the profile outright, from the profile page.
     *
     * Unlike absorb(), a blank optional field here clears it: the profile form
     * shows everything on file, so an empty input is an instruction rather than
     * an omission.
     */
    public function reviseProfile(
        string $fullName,
        string $phone,
        ?string $email,
        ?string $notes,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->fullName = trim($fullName);
        $this->phone = trim($phone);
        $this->email = self::normalizeEmail($email);
        $this->notes = self::normalize($notes);
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    private static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Email addresses are stored lower-cased, so "Ada@Example.Test" and
     * "ada@example.test" are one customer rather than two.
     */
    private static function normalizeEmail(?string $email): ?string
    {
        $value = self::normalize($email);

        return $value === null ? null : mb_strtolower($value);
    }
}
