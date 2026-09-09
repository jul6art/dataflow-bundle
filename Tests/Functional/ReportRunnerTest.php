<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntity;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntityProviderInterface;
use Jul6Art\DataflowBundle\Report\ReportResult;
use Jul6Art\DataflowBundle\Report\ReportRunner;
use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Report\Spec\ReportColumn;
use Jul6Art\DataflowBundle\Report\Spec\ReportFilter;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpec;
use Jul6Art\DataflowBundle\Report\Transformer\DateTimeValueTransformer;
use Jul6Art\DataflowBundle\Report\Transformer\EnumValueTransformer;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Invoice;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\InvoiceStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The runner, against a real database.
 *
 * ⚠️ The assertion this file exists for is {@see self::testRowsAreStreamedRatherThanMaterialised()}.
 * It asserts the TYPE and the interleaving, not the content — the defect it replaces returned
 * every row correctly while holding two complete copies of the result set, so no content assertion
 * could ever have seen it.
 */
#[CoversClass(ReportRunner::class)]
#[CoversClass(ReportResult::class)]
final class ReportRunnerTest extends AbstractFunctionalTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    public function testAWholeReport(): void
    {
        $this->seed(3);

        $result = $this->runner()->run($this->spec(), $this->actor());

        self::assertSame(['Number', 'Customer'], $result->header());
        self::assertSame(
            [
                ['number' => 'INV-1', 'customer.name' => 'Client 1'],
                ['number' => 'INV-2', 'customer.name' => 'Client 2'],
                ['number' => 'INV-3', 'customer.name' => 'Client 3'],
            ],
            iterator_to_array($result->rows()),
        );
    }

    /**
     * The property the whole layer exists for, asserted by MEASUREMENT.
     *
     * ⚠️ A first version of this test asserted `assertInstanceOf(\Generator::class, …)` plus an
     * interleaving counter, and **the mutation proved it worthless**: putting `getArrayResult()`
     * back left it green. The closure is a generator function either way — it contains `yield` —
     * so the type says nothing about where the rows came from, and the counter asserted a fact
     * about itself.
     *
     * What actually differs is peak memory, and it differs by a lot. Measured on this fixture set:
     * `toIterable()` costs nothing measurable, `getArrayResult()` costs 4 MB, because it hydrates
     * the whole result before the first `yield`. The ceiling below sits between the two with room
     * to spare, so the assertion is about the property rather than about a number.
     */
    public function testRowsAreStreamedRatherThanMaterialised(): void
    {
        $this->seedBulk(6000);

        $spec = new ReportSpec(Invoice::class, [new ReportColumn('number', 'Number')]);

        gc_collect_cycles();
        $before = memory_get_usage(true);

        $seen = 0;

        foreach ($this->runner()->run($spec, $this->actor(), limit: 6000)->rows() as $_) {
            ++$seen;
        }

        $cost = memory_get_peak_usage(true) - $before;

        self::assertSame(6000, $seen, 'Every row still arrives.');
        self::assertLessThan(
            2 * 1024 * 1024,
            $cost,
            \sprintf(
                'Streaming 6000 rows cost %.2f MB. Materialising them costs about 4 MB, so this '
                .'ceiling is what tells the two apart.',
                $cost / 1048576,
            ),
        );
    }

    /**
     * ⚠️ A generator is single-use, and that is a contract rather than a defect: a caller needing
     * the rows twice runs the report twice, so the cost is a decision instead of a default.
     */
    public function testTheRowStreamIsSingleUse(): void
    {
        $this->seed(2);

        $result = $this->runner()->run($this->spec(), $this->actor());
        $rows = $result->rows();

        iterator_to_array($rows);

        $this->expectException(\Exception::class);
        iterator_to_array($rows);
    }

    /**
     * ⚠️ One join per relation, shared between columns and filters. Two columns behind the same
     * relation must not produce two joins — the version this replaces got that right and it is
     * worth keeping right.
     */
    public function testARelationIsJoinedOnceForSeveralColumns(): void
    {
        $this->seed(1);

        $spec = new ReportSpec(Invoice::class, [
            new ReportColumn('customer.name', 'Name'),
            new ReportColumn('customer.email', 'Email'),
        ], [
            new ReportFilter('customer.name', FilterOperator::Contains, 'Client'),
        ]);

        $rows = iterator_to_array($this->runner()->run($spec, $this->actor())->rows());

        self::assertSame([['customer.name' => 'Client 1', 'customer.email' => 'c1@example.test']], $rows);
    }

    /**
     * ⚠️ A left join, never an inner one: adding an optional column must not silently shrink the
     * report. This invoice has no customer at all.
     */
    public function testARowWithoutItsRelationSurvives(): void
    {
        $this->seed(1);
        $orphan = new Invoice();
        $orphan->number = 'INV-ORPHAN';
        $this->entityManager()->persist($orphan);
        $this->entityManager()->flush();

        $rows = iterator_to_array($this->runner()->run($this->spec(), $this->actor())->rows());

        self::assertCount(2, $rows, 'The invoice with no customer is still reported.');
        self::assertSame(['number' => 'INV-ORPHAN', 'customer.name' => null], $rows[1]);
    }

    public function testValuesGoThroughTheTransformerChain(): void
    {
        $this->seed(1);

        $spec = new ReportSpec(Invoice::class, [
            new ReportColumn('status', 'Status'),
            new ReportColumn('issuedAt', 'Issued'),
        ]);

        $rows = iterator_to_array($this->runner()->run($spec, $this->actor())->rows());

        self::assertSame('paid', $rows[0]['status'], 'The enum came back as its backing value.');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $rows[0]['issuedAt']);
    }

    /**
     * ⚠️ The gate is re-checked HERE. A crafted payload reaches this method without passing any
     * dropdown.
     */
    public function testAnUnreportableEntityIsRefused(): void
    {
        $this->seed(1);

        $this->expectException(\DomainException::class);

        // ⚠️ Refused because `Customer` is not IN the catalogue — only `Invoice` is registered
        // above. The permission is beside the point here, and a first version of this test passed
        // a permission list that did nothing, which made it read as if it were the reason.
        $this->runner()->run(new ReportSpec(Customer::class, [new ReportColumn('name', 'n')]), $this->actor());
    }

    /**
     * ⚠️ And a FILTER path is checked too. It never appears in the output, so it looks harmless —
     * but it lands in the WHERE clause and leaks the field by which rows come back.
     */
    public function testAFilterOnAForbiddenFieldIsRefused(): void
    {
        $this->seed(1);

        $this->expectException(\DomainException::class);

        $this->runner()->run(new ReportSpec(
            Invoice::class,
            [new ReportColumn('number', 'Number')],
            [new ReportFilter('customer.account.password', FilterOperator::Equals, 'x')],
        ), $this->actor());
    }

    public function testFiltersNarrowTheResult(): void
    {
        $this->seed(4);

        $spec = new ReportSpec(
            Invoice::class,
            [new ReportColumn('number', 'Number')],
            [new ReportFilter('number', FilterOperator::In, ['INV-2', 'INV-4'])],
        );

        self::assertSame(
            [['number' => 'INV-2'], ['number' => 'INV-4']],
            iterator_to_array($this->runner()->run($spec, $this->actor())->rows()),
        );
    }

    /**
     * ⚠️ The null check goes through the relation's IDENTIFIER, not the relation itself. The field
     * catalogue lists SELECTABLE SCALARS, so `customer` alone is not a path and is refused — which
     * is the right contract: a catalogue that also listed bare relations would offer things that
     * cannot be columns, and the runner would have to special-case a path with no scalar at its
     * end. `customer.id IS NOT NULL` says the same thing and stays inside the contract.
     */
    public function testAValuelessFilterBindsNoParameter(): void
    {
        $this->seed(2);

        $spec = new ReportSpec(
            Invoice::class,
            [new ReportColumn('number', 'Number')],
            [new ReportFilter('customer.id', FilterOperator::IsNotNull)],
        );

        self::assertCount(2, iterator_to_array($this->runner()->run($spec, $this->actor())->rows()));
    }

    /**
     * And its counterpart, so the operator is proven to discriminate rather than to pass
     * everything: the orphan invoice is the only one `IS NULL` returns.
     */
    public function testAValuelessFilterActuallyDiscriminates(): void
    {
        $this->seed(2);
        $orphan = new Invoice();
        $orphan->number = 'INV-ORPHAN';
        $this->entityManager()->persist($orphan);
        $this->entityManager()->flush();

        $spec = new ReportSpec(
            Invoice::class,
            [new ReportColumn('number', 'Number')],
            [new ReportFilter('customer.id', FilterOperator::IsNull)],
        );

        self::assertSame(
            [['number' => 'INV-ORPHAN']],
            iterator_to_array($this->runner()->run($spec, $this->actor())->rows()),
        );
    }

    /**
     * ⚠️ Scoping belongs to the application. The bundle does not know what a tenant is, so it takes
     * a closure — inventing an `organization` column would fit one of three consumers and silently
     * return everything for the other two.
     */
    public function testTheCallersScopeIsApplied(): void
    {
        $this->seed(3);

        $rows = iterator_to_array($this->runner()->run(
            $this->spec(),
            $this->actor(),
            scope: static function (QueryBuilder $qb, string $root): void {
                $qb->andWhere($root.'.number = :n')->setParameter('n', 'INV-2');
            },
        )->rows());

        self::assertSame([['number' => 'INV-2', 'customer.name' => 'Client 2']], $rows);
    }

    public function testASpecWithoutColumnsYieldsNothing(): void
    {
        $this->seed(2);

        $result = $this->runner()->run(new ReportSpec(Invoice::class, []), $this->actor());

        self::assertSame([], $result->header());
        self::assertSame([], iterator_to_array($result->rows()));
    }

    public function testTheLimitIsHonoured(): void
    {
        $this->seed(10);

        $rows = iterator_to_array($this->runner()->run($this->spec(), $this->actor(), limit: 3)->rows());

        self::assertCount(3, $rows);
    }

    private function spec(): ReportSpec
    {
        return new ReportSpec(Invoice::class, [
            new ReportColumn('number', 'Number'),
            new ReportColumn('customer.name', 'Customer'),
        ]);
    }

    /**
     * @param list<string> $granted
     */
    private function runner(array $granted = ['invoice:read', 'customer:read']): ReportRunner
    {
        $entityManager = $this->entityManager();

        $permissions = self::createStub(PermissionDecisionService::class);
        $permissions->method('isGranted')->willReturnCallback(
            static fn (AclUserInterface $user, string $code): bool => \in_array($code, $granted, true),
        );

        $entities = new EntityCatalog($permissions, null, [
            new class implements ReportableEntityProviderInterface {
                public function entities(): array
                {
                    return [Invoice::class => new ReportableEntity('invoice', 'invoice:read')];
                }
            },
        ]);

        return new ReportRunner(
            $entityManager,
            $entities,
            new FieldCatalog($entityManager, $entities),
            new ValueTransformerChain([new DateTimeValueTransformer(), new EnumValueTransformer()]),
        );
    }

    private function seed(int $count): void
    {
        $entityManager = $this->entityManager();

        $tool = new SchemaTool($entityManager);
        $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        for ($i = 1; $i <= $count; ++$i) {
            $customer = new Customer();
            $customer->name = 'Client '.$i;
            $customer->email = 'c'.$i.'@example.test';
            $entityManager->persist($customer);

            $invoice = new Invoice();
            $invoice->number = 'INV-'.$i;
            $invoice->customer = $customer;
            $invoice->status = InvoiceStatus::Paid;
            $entityManager->persist($invoice);
        }

        $entityManager->flush();
    }

    /**
     * Seeds many invoices cheaply: no relation, one long column, flushed in batches so the unit of
     * work does not become the thing being measured.
     */
    private function seedBulk(int $count): void
    {
        $entityManager = $this->entityManager();

        $tool = new SchemaTool($entityManager);
        $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $filler = str_repeat('x', 400);

        for ($i = 0; $i < $count; ++$i) {
            $invoice = new Invoice();
            $invoice->number = $filler.$i;
            $entityManager->persist($invoice);

            if (0 === $i % 500) {
                $entityManager->flush();
                $entityManager->clear();
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        if ($this->entityManager instanceof EntityManagerInterface) {
            return $this->entityManager;
        }

        $entityManager = $this->boot(withOrm: true)->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $this->entityManager = $entityManager;
    }

    private function actor(): AclUserInterface
    {
        $actor = self::createStub(AclUserInterface::class);
        $actor->method('getId')->willReturn(1);
        $actor->method('isActive')->willReturn(true);
        $actor->method('isSuperAdmin')->willReturn(false);
        $actor->method('getRoles')->willReturn(['ROLE_USER']);

        return $actor;
    }
}
