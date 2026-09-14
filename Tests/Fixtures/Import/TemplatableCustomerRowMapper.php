<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Import;

use Jul6Art\DataflowBundle\Import\TemplatableRowMapperInterface;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;

/**
 * A mapper that answers {@see TemplatableRowMapperInterface} — separate from
 * {@see CustomerRowMapper} so that fixture stays the plain, most-common shape a mapper takes, and
 * this one exists only to give {@see \Jul6Art\DataflowBundle\Tests\Import\TemplateBuilderTest}
 * something to introspect.
 *
 * ⚠️ `status` is deliberately covered by `enumeratedValues()` but ABSENT from `exampleRow()` — the
 * two are independent, and a template must still show a blank cell for it rather than nothing.
 */
final readonly class TemplatableCustomerRowMapper implements TemplatableRowMapperInterface
{
    #[\Override]
    public function fields(): array
    {
        return ['name', 'email', 'status'];
    }

    #[\Override]
    public function exampleRow(): array
    {
        return ['name' => 'Ada Lovelace', 'email' => 'ada@example.test'];
    }

    #[\Override]
    public function enumeratedValues(): array
    {
        return ['status' => ['active', 'inactive']];
    }

    #[\Override]
    public function map(array $row, ?object $existing = null): Customer
    {
        $customer = $existing instanceof Customer ? $existing : new Customer();
        $customer->name = $row['name'] ?? '';
        $customer->email = $row['email'] ?? null;

        return $customer;
    }
}
