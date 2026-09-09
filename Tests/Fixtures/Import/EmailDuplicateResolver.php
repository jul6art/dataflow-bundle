<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Import;

use Doctrine\ORM\EntityManagerInterface;
use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;

/**
 * Resolves a whole batch in ONE query, which is what the contract exists to force.
 *
 * `$queries` is public so a test can assert the count: the implementation this replaces issued one
 * `SELECT` per row, and no assertion about the RESULT could have seen that.
 */
final class EmailDuplicateResolver implements DuplicateResolverInterface
{
    public int $queries = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function keyOf(array $row): ?string
    {
        return isset($row['email']) ? strtolower($row['email']) : null;
    }

    #[\Override]
    public function findExisting(array $rows): array
    {
        $emails = [];

        foreach ($rows as $index => $row) {
            if (isset($row['email'])) {
                $emails[$index] = strtolower($row['email']);
            }
        }

        if ([] === $emails) {
            return [];
        }

        ++$this->queries;

        /** @var list<Customer> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Customer::class, 'c')
            ->where('LOWER(c.email) IN (:emails)')
            ->setParameter('emails', array_values($emails))
            ->getQuery()
            ->getResult();

        $byEmail = [];

        foreach ($found as $customer) {
            $byEmail[strtolower((string) $customer->email)] = $customer;
        }

        $existing = [];

        foreach ($emails as $index => $email) {
            if (isset($byEmail[$email])) {
                $existing[$index] = $byEmail[$email];
            }
        }

        return $existing;
    }
}
