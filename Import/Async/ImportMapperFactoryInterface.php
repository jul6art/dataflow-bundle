<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Async;

use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Import\RowMapperInterface;

/**
 * Builds the mapper — and, optionally, the resolver — an {@see ImportMessage} names, given the
 * scalar context it carried across the queue.
 *
 * ## The problem this solves
 *
 * ⚠️ **A `RowMapperInterface` is usually not a stateless singleton.** `CustomerRowMapper` holds an
 * `Account` — the tenant a controller passed in — and that object cannot cross a message queue: a
 * real transport serialises the message to text, and an entity reference does not survive that
 * (nor should it — the row it names may no longer be current by the time a worker picks the message
 * up). `ImportMessage` therefore carries `context`, plain scalars only (an account id, most often),
 * and THIS factory is what turns `mapperId` + that context back into a real, correctly-scoped
 * `RowMapperInterface` — inside the worker, where a fresh `EntityManager` and a fresh query are
 * exactly what re-attaching that context correctly requires.
 *
 * ## Why a port, not a registry this bundle owns
 *
 * ⚠️ `mapperId` is a string an application invents (`'customer-import'`, `'user-import'`) — this
 * bundle has no vocabulary of its own consumer's mappers, the same reason
 * `Port\ReportDefinitionStoreInterface` is not aliased to anything. Deliberately NOT aliased to a
 * default for the same reason: there is no mapper this bundle could build, for any id, on any
 * consumer's behalf.
 *
 * ⚠️ **Unlike `ReportDefinitionStoreInterface`, an absent binding here does not fail the
 * container.** {@see ImportMessageHandler} is autowired for every application that configures
 * `symfony/messenger`, not only for the ones that use this bundle's async import — a required,
 * defaultless argument would fail an unrelated application's boot. The handler accepts `null` and
 * refuses loudly at the moment a message actually reaches it instead.
 */
interface ImportMapperFactoryInterface
{
    /**
     * @param array<string, bool|int|float|string|null> $context
     *
     * @throws \InvalidArgumentException when $mapperId names no mapper this application builds
     */
    public function mapper(string $mapperId, array $context): RowMapperInterface;

    /**
     * @param array<string, bool|int|float|string|null> $context
     *
     * @throws \InvalidArgumentException when $resolverId is non-null and names no resolver this
     *                                    application builds
     */
    public function resolver(?string $resolverId, array $context): ?DuplicateResolverInterface;
}
