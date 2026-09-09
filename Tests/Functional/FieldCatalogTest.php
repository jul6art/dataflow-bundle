<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldPolicyInterface;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntity;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntityProviderInterface;
use Jul6Art\DataflowBundle\Report\Catalog\ReportField;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Customer;
use Jul6Art\DataflowBundle\Tests\Fixtures\Entity\Invoice;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The field catalogue, against REAL Doctrine metadata.
 *
 * ⚠️ It is a functional test rather than a unit one on purpose. Everything this class does is read
 * `ClassMetadata`, and a mocked `ClassMetadata` would only ever confirm what the mock was told —
 * including that a `OneToMany` is a `OneToMany`, which is exactly the fact that must not be assumed.
 * The fixture entities under `Tests/Fixtures/Entity` carry one of every case.
 */
#[CoversClass(FieldCatalog::class)]
final class FieldCatalogTest extends AbstractFunctionalTestCase
{
    public function testTheRootsOwnScalarsAreOffered(): void
    {
        $paths = $this->paths($this->catalog());

        self::assertContains('number', $paths);
        self::assertContains('total', $paths);
        self::assertContains('paid', $paths);
        self::assertContains('status', $paths);
    }

    /**
     * ⚠️ The Doctrine type, not a guess. A caller picks a filter widget and a column format from
     * it, and eleven columns of one application carried the wrong renderer because someone chose
     * by hand instead.
     */
    public function testEachFieldCarriesItsDoctrineType(): void
    {
        $types = [];

        foreach ($this->catalog()->listFor(Invoice::class, $this->actor()) as $field) {
            $types[$field->path] = $field->type;
        }

        self::assertSame('decimal', $types['total']);
        self::assertSame('date_immutable', $types['issuedAt']);
        self::assertSame('datetime_immutable', $types['createdAt']);
        self::assertSame('boolean', $types['paid']);
    }

    public function testAToOneRelationIsTraversedAndItsFieldsAreMarked(): void
    {
        $fields = [];

        foreach ($this->catalog()->listFor(Invoice::class, $this->actor()) as $field) {
            $fields[$field->path] = $field;
        }

        self::assertArrayHasKey('customer.name', $fields);
        self::assertTrue($fields['customer.name']->traversed);
        self::assertFalse($fields['number']->traversed);
    }

    /**
     * ⚠️ The refusal that protects the row count. One invoice with four lines would come back as
     * four rows, and an export of a thousand invoices would silently multiply — the user sees more
     * rows than there are records and cannot tell why.
     */
    public function testAToManyRelationIsNeverTraversed(): void
    {
        foreach ($this->paths($this->catalog()) as $path) {
            self::assertStringNotContainsString('lines.', $path, 'A collection must not be walked.');
        }
    }

    /**
     * ⚠️ Whatever the entity, whatever the permission. `Account::$password` sits two hops from the
     * root and must not appear.
     */
    public function testAGloballyDeniedNameIsNeverOffered(): void
    {
        $paths = $this->paths($this->catalog());

        self::assertContains('customer.account.label', $paths, 'The account IS reachable…');
        self::assertNotContains('customer.account.password', $paths, '…but its password is not.');
    }

    /**
     * ⚠️ A relation whose target is a catalogued entity is gated on that target. Without this, an
     * actor holding the root's permission and not the target's reads `invoice.customer.email` —
     * the root's permission silently granting the related entity.
     */
    public function testACataloguedTargetIsGatedOnItsOwnPermission(): void
    {
        $paths = $this->paths($this->catalog(granted: ['invoice:read']));

        self::assertContains('number', $paths, 'The root is still reportable.');
        self::assertNotContains('customer.name', $paths, 'The customer is catalogued and refused.');
        self::assertNotContains('customer.account.label', $paths, 'And so is everything behind it.');
    }

    /**
     * ⚠️ The counterpart, and it is what keeps the catalogue usable: a target that is NOT in the
     * catalogue is a referential — a country, a unit, a tax rate — and traversing it must not
     * require an entry. `Account` is deliberately absent from the catalogue here.
     */
    public function testAnUncataloguedTargetIsTraversedFreely(): void
    {
        self::assertContains('customer.account.label', $this->paths($this->catalog()));
    }

    /**
     * ⚠️ A self-reference must terminate by depth rather than loop. `Invoice.creditedInvoice` is an
     * `Invoice`, so an unbounded walk would never return.
     */
    public function testASelfReferenceTerminatesOnDepth(): void
    {
        $paths = $this->paths($this->catalog(), maxDepth: 2);

        self::assertContains('creditedInvoice.number', $paths);
        self::assertNotContains('creditedInvoice.creditedInvoice.creditedInvoice.number', $paths);
    }

    public function testDepthZeroOffersOnlyTheRoot(): void
    {
        $paths = $this->paths($this->catalog(), maxDepth: 0);

        self::assertContains('number', $paths);
        self::assertNotContains('customer.name', $paths);
    }

    /**
     * ⚠️ The tool that unblocks a whole module. A global name list cannot distinguish a name that
     * is sensitive on one entity from the same name on another; a per-entity policy can.
     */
    public function testAFieldPolicyNarrowsPerEntity(): void
    {
        $policy = new class implements FieldPolicyInterface {
            public function allows(string $entity, string $field, AclUserInterface $actor): bool
            {
                return Customer::class !== $entity || 'email' !== $field;
            }
        };

        $paths = $this->paths($this->catalog(policy: $policy));

        self::assertNotContains('customer.email', $paths, 'Denied on Customer…');
        self::assertContains('customer.name', $paths, '…and only there.');
    }

    /**
     * ⚠️ A policy can only NARROW. It runs after the global list, so returning true never reopens
     * what that already refused — otherwise a project-side class could widen a bundle guarantee.
     */
    public function testAPermissivePolicyCannotReopenAGloballyDeniedName(): void
    {
        $policy = new class implements FieldPolicyInterface {
            public function allows(string $entity, string $field, AclUserInterface $actor): bool
            {
                return true;
            }
        };

        self::assertNotContains('customer.account.password', $this->paths($this->catalog(policy: $policy)));
    }

    /**
     * ⚠️ The runtime guard: the screen is not the only way in.
     */
    public function testACraftedPathIsRefusedAtRuntime(): void
    {
        $this->expectException(\DomainException::class);

        $this->catalog()->assertPathAllowed(Invoice::class, $this->actor(), 'customer.account.password');
    }

    public function testAnOfferedPathPassesTheRuntimeGuard(): void
    {
        $catalog = $this->catalog();

        $catalog->assertPathAllowed(Invoice::class, $this->actor(), 'customer.name');

        self::assertTrue($catalog->isPathAllowed(Invoice::class, $this->actor(), 'customer.name'));
    }

    /**
     * ⚠️ The fix for the walk replayed once per path. Thirteen checks, one walk — and the walk is
     * the expensive half, since it re-asks the entity catalogue for every traversed relation.
     */
    public function testTheWalkIsMemoisedAcrossPathChecks(): void
    {
        $catalog = $this->catalog();
        $actor = $this->actor();

        $first = $catalog->listFor(Invoice::class, $actor);

        for ($i = 0; $i < 13; ++$i) {
            $catalog->isPathAllowed(Invoice::class, $actor, 'customer.name');
        }

        self::assertSame($first, $catalog->listFor(Invoice::class, $actor));
    }

    /**
     * @param list<string> $granted
     */
    private function catalog(
        array $granted = ['invoice:read', 'customer:read'],
        ?FieldPolicyInterface $policy = null,
    ): FieldCatalog {
        $container = $this->boot(withOrm: true);
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $permissions = self::createStub(PermissionDecisionService::class);
        $permissions->method('isGranted')->willReturnCallback(
            static fn (AclUserInterface $user, string $code): bool => \in_array($code, $granted, true),
        );

        $entities = new EntityCatalog($permissions, null, [
            new class implements ReportableEntityProviderInterface {
                public function entities(): array
                {
                    return [
                        Invoice::class => new ReportableEntity('invoice', 'invoice:read'),
                        Customer::class => new ReportableEntity('customer', 'customer:read'),
                        // ⚠️ Account is deliberately ABSENT: it stands for a referential.
                    ];
                }
            },
        ]);

        return new FieldCatalog($entityManager, $entities, $policy);
    }

    /**
     * @return list<string>
     */
    private function paths(FieldCatalog $catalog, int $maxDepth = FieldCatalog::DEFAULT_MAX_DEPTH): array
    {
        return array_map(
            static fn (ReportField $field): string => $field->path,
            $catalog->listFor(Invoice::class, $this->actor(), $maxDepth),
        );
    }

    private function actor(int $id = 1): AclUserInterface
    {
        $actor = self::createStub(AclUserInterface::class);
        $actor->method('getId')->willReturn($id);
        $actor->method('isActive')->willReturn(true);
        $actor->method('isSuperAdmin')->willReturn(false);
        $actor->method('getRoles')->willReturn(['ROLE_USER']);

        return $actor;
    }
}
