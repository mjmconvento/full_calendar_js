<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 *
 * Every date parameter is bound as DATE_IMMUTABLE on purpose. Without the
 * explicit type Doctrine infers "datetime" from the PHP value and sends
 * "2026-09-10 00:00:00", which never equals a DATE column on SQLite and only
 * matches on PostgreSQL thanks to an implicit cast.
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Reservations on one day, in the order the day page reads them: earliest
     * preferred time first, untimed bookings after the timed ones, then by id so
     * two bookings at the same time keep the order they were taken in.
     *
     * The CASE is explicit because SQLite sorts NULL first in an ascending
     * order (PostgreSQL happens to sort it last), which would put "no time
     * agreed" at the top of the day instead of at the end where it belongs.
     *
     * @return list<Reservation>
     */
    public function findOn(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.reservedOn = :date')
            ->setParameter('date', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('CASE WHEN r.startsAt IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('r.startsAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of reservations, oldest day first.
     *
     * `$from` and `$until` are optional; omitting both pages over every
     * reservation on file. The window is half-open ($from inclusive, $until
     * exclusive), so the upper bound matches what FullCalendar asks for - and
     * the legacy inclusive `BETWEEN`, which leaked one extra day, is not
     * repeated.
     *
     * @return list<Reservation>
     */
    public function findRangePage(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $until,
        int $offset,
        int $limit,
    ): array {
        $builder = $this->createQueryBuilder('r')
            ->orderBy('r.reservedOn', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->boundRange($builder, $from, $until);

        return $builder->getQuery()->getResult();
    }

    /**
     * How many reservations the same window holds, so a caller can tell whether
     * the page it just read was the last one.
     */
    public function countRange(?\DateTimeImmutable $from, ?\DateTimeImmutable $until): int
    {
        $builder = $this->createQueryBuilder('r')->select('COUNT(r.id)');

        $this->boundRange($builder, $from, $until);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * One page of every reservation on file, latest day first.
     *
     * The dashboard's list is paged here rather than in PHP: the legacy app
     * echoed every row of `calendar` on every request, which stopped being
     * viable the moment the table grew past a screenful.
     *
     * It reads as a ledger rather than as a calendar, so it runs the other way
     * round from findRangePage(): the most recent booking is the one an
     * operator is usually looking for, and within a day the newest booking
     * comes first for the same reason.
     *
     * @return list<Reservation>
     */
    public function findPage(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.reservedOn', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every booking belonging to one customer, oldest first.
     *
     * A profile page is a history, so the order runs forwards: the most recent
     * booking is the one at the bottom, which is where a reader who scrolled to
     * the end expects it.
     *
     * @return list<Reservation>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('r.reservedOn', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOn(\DateTimeImmutable $date): int
    {
        return $this->countRange($date, $date->modify('+1 day'));
    }

    /**
     * Reservations still to come, counted from the beginning of $from.
     */
    public function countFrom(\DateTimeImmutable $from): int
    {
        return $this->countRange($from, null);
    }

    public function countAll(): int
    {
        return $this->countRange(null, null);
    }

    /**
     * Applies a window to a query builder. Both bounds are bound as
     * DATE_IMMUTABLE for the reason given in the class docblock.
     */
    private function boundRange(
        QueryBuilder $builder,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $until,
    ): void {
        if ($from !== null) {
            $builder->andWhere('r.reservedOn >= :from')
                ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE);
        }

        if ($until !== null) {
            $builder->andWhere('r.reservedOn < :until')
                ->setParameter('until', $until->setTime(0, 0), Types::DATE_IMMUTABLE);
        }
    }
}
