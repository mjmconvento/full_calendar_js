<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Customers whose name, phone or email contains the given text.
     *
     * An empty query lists the most recently added customers instead of
     * everything: that is what an operator sees when they open the picker, and
     * it is the useful set - the people they have dealt with lately. The result
     * count is always bounded, so this can be called on every keystroke.
     *
     * @return list<Customer>
     */
    public function search(?string $query, int $limit = 10): array
    {
        $term = $query === null ? '' : trim($query);

        if ($term === '') {
            return $this->createQueryBuilder('c')
                ->orderBy('c.updatedAt', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        $builder = $this->createQueryBuilder('c')
            ->orderBy('LOWER(c.fullName)', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit);

        $this->filterByTerm($builder, $term);

        return $builder->getQuery()->getResult();
    }

    /**
     * One page of the directory: the customer whose booking is closest to today
     * first, then the next closest, and so on.
     *
     * Only bookings on or before $today count towards the order. A booking that
     * is still to come says nothing about how recently the operator has dealt
     * with the person, so it must not lift them up the list: a customer whose
     * only booking is next month sits with the ones who have no booking at all.
     * Those come last, alphabetically.
     *
     * Where the "no past booking" customers land is spelled out with a CASE
     * rather than left to the engine: PostgreSQL sorts NULL *first* in a
     * descending order, SQLite last. Without it, the customers nobody has
     * dealt with yet would head the directory in production and close it in
     * the test suite, and every test would pass. The name tie-break goes
     * through LOWER() for the same reason: MySQL's default collation sorted
     * names case-insensitively, and PostgreSQL's depends on the database's
     * collation (code-point order on the alpine image, so "dasd" would follow
     * "MJ"). Lower-casing makes it the same everywhere.
     *
     * @return list<Customer>
     */
    public function findDirectoryPage(string $query, \DateTimeImmutable $today, int $offset, int $limit): array
    {
        $builder = $this->createQueryBuilder('c')
            ->leftJoin('c.reservations', 'r', Join::WITH, 'r.reservedOn <= :today')
            ->setParameter('today', $today->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->groupBy('c.id')
            ->orderBy('CASE WHEN MAX(r.reservedOn) IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('MAX(r.reservedOn)', 'DESC')
            ->addOrderBy('LOWER(c.fullName)', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->filterByTerm($builder, $query);

        return $builder->getQuery()->getResult();
    }

    /**
     * How many customers the same directory filter matches, so a page can tell
     * whether there is another one after it.
     */
    public function countDirectory(string $query): int
    {
        $builder = $this->createQueryBuilder('c')->select('COUNT(c.id)');

        $this->filterByTerm($builder, $query);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * The customer a booking belongs to, and how confident that match is.
     *
     * Contact details are tried before the name, and the order matters: a phone
     * number or an email address identifies a person, whereas a name is shared
     * by everyone with that name. The caller needs to know which one hit,
     * because it decides whether the submitted details may overwrite what is
     * stored - see App\Booking\CustomerResolver.
     *
     * A blank name is not looked up: an empty string would match every customer
     * whose name is also empty, and there should be none, but the query is not
     * worth running to find out.
     */
    public function findByIdentity(string $fullName, string $phone, ?string $email): ?CustomerMatch
    {
        foreach ([[CustomerMatch::BY_PHONE, 'c.phone', $phone], [CustomerMatch::BY_EMAIL, 'c.email', $email]] as [$reason, $field, $value]) {
            if ($value === null) {
                continue;
            }

            $match = $this->createQueryBuilder('c')
                ->andWhere(sprintf('LOWER(%s) = :value', $field))
                ->setParameter('value', mb_strtolower(trim($value)))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($match instanceof Customer) {
                return new CustomerMatch($match, $reason);
            }
        }

        $name = trim($fullName);

        if ($name === '') {
            return null;
        }

        $match = $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.fullName) = :name')
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $match instanceof Customer ? new CustomerMatch($match, CustomerMatch::BY_NAME) : null;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many bookings each of these customers has, keyed by customer id.
     *
     * One query for a whole page of the directory: asking per customer would be
     * a query per row.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, int>
     */
    public function bookingCounts(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        /** @var list<array{customerId: int, bookings: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(r.customer) AS customerId, COUNT(r.id) AS bookings')
            ->from(\App\Entity\Reservation::class, 'r')
            ->andWhere('r.customer IN (:ids)')
            ->setParameter('ids', $customerIds)
            ->groupBy('customerId')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['customerId']] = (int) $row['bookings'];
        }

        return $counts;
    }

    /**
     * Applies the directory's "contains this text" filter to a query builder.
     *
     * LOWER() on both sides keeps this working the same way on PostgreSQL and
     * SQLite: LIKE is case-sensitive on both, and case-insensitive matching is
     * what a directory search means.
     */
    private function filterByTerm(QueryBuilder $builder, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $builder->andWhere('LOWER(c.fullName) LIKE :pattern OR LOWER(c.phone) LIKE :pattern OR LOWER(c.email) LIKE :pattern')
            ->setParameter('pattern', '%'.mb_strtolower($term).'%');
    }
}
