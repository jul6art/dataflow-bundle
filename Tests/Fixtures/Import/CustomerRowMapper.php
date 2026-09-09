<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Import;

use Jul6Art\DataflowBundle\Import\RowMapperInterface;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Account;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;

/**
 * A consumer's mapper, in the shape the contract asks for.
 *
 * The held `$account` is deliberate: it stands for the tenant a controller passes in, and it is the
 * thing `EntityManager::clear()` would detach behind the mapper's back. Holding it across every
 * batch is what the runner's choice of `detach()` makes safe.
 */
final readonly class CustomerRowMapper implements RowMapperInterface
{
    public function __construct(
        private ?Account $account = null,
    ) {
    }

    #[\Override]
    public function fields(): array
    {
        return ['name', 'email'];
    }

    #[\Override]
    public function map(array $row, ?object $existing = null): Customer
    {
        if (!isset($row['name'])) {
            throw new \DomainException('test.import.error.missing_name');
        }

        $customer = new Customer();
        $customer->name = $row['name'];
        $customer->email = $row['email'] ?? null;
        $customer->account = $this->account;

        return $customer;
    }
}
