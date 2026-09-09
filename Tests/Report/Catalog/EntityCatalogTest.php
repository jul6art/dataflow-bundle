<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Catalog;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntity;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntityProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The entity catalogue and its two gates.
 *
 * ⚠️ Two assertions here carry the design rather than a behaviour.
 * {@see self::testTheAnswerIsMemoisedPerActor()} is the fix for a runner that re-ran every feature
 * and permission check thirteen times for a thirteen-path report, and
 * {@see self::testWithoutAFeatureCheckerOnlyThePermissionGateApplies()} is what lets a
 * single-product application — two of this bundle's three targets — use the engine at all.
 */
#[CoversClass(EntityCatalog::class)]
#[CoversClass(ReportableEntity::class)]
final class EntityCatalogTest extends TestCase
{
    public function testAnEntryPassingBothGatesIsListed(): void
    {
        $catalog = $this->catalog(granted: ['crm:contact:read'], enabled: ['crm.manage']);

        self::assertArrayHasKey(\DateTimeImmutable::class, $catalog->listFor($this->actor()));
    }

    public function testAnEntryWhoseFeatureIsOffIsHidden(): void
    {
        $catalog = $this->catalog(granted: ['crm:contact:read'], enabled: []);

        self::assertSame([], $catalog->listFor($this->actor()));
    }

    public function testAnEntryWhosePermissionIsMissingIsHidden(): void
    {
        $catalog = $this->catalog(granted: [], enabled: ['crm.manage']);

        self::assertSame([], $catalog->listFor($this->actor()));
    }

    /**
     * ⚠️ Two of this bundle's three target applications bind no feature checker: feature flags are
     * a multi-tenant concern and a single-product application has none. The permission gate still
     * applies — it is never optional, because a report engine without one is an exfiltration tool.
     */
    public function testWithoutAFeatureCheckerOnlyThePermissionGateApplies(): void
    {
        $catalog = new EntityCatalog(
            $this->permissions(['crm:contact:read']),
            null,
            [$this->provider(new ReportableEntity('label', 'crm:contact:read', 'crm.manage'))],
        );

        self::assertArrayHasKey(
            \DateTimeImmutable::class,
            $catalog->listFor($this->actor()),
            'A declared feature is not enforced when nothing can check it.',
        );

        $refused = new EntityCatalog(
            $this->permissions([]),
            null,
            [$this->provider(new ReportableEntity('label', 'crm:contact:read', 'crm.manage'))],
        );

        self::assertSame([], $refused->listFor($this->actor()), 'The permission half still refuses.');
    }

    public function testAnEntryDeclaringNoFeatureNeedsNoChecker(): void
    {
        $catalog = new EntityCatalog(
            $this->permissions(['crm:contact:read']),
            null,
            [$this->provider(new ReportableEntity('label', 'crm:contact:read'))],
        );

        self::assertArrayHasKey(\DateTimeImmutable::class, $catalog->listFor($this->actor()));
    }

    /**
     * ⚠️ The fix for the thirteen-times replay. The permission service is asked ONCE per actor, no
     * matter how many times the catalogue is consulted — and a runner consults it once per column
     * and once per filter.
     */
    public function testTheAnswerIsMemoisedPerActor(): void
    {
        $permissions = $this->createMock(PermissionDecisionService::class);
        $permissions->expects(self::once())->method('isGranted')->willReturn(true);

        $catalog = new EntityCatalog($permissions, null, [
            $this->provider(new ReportableEntity('label', 'crm:contact:read')),
        ]);

        $actor = $this->actor();

        for ($i = 0; $i < 13; ++$i) {
            $catalog->listFor($actor);
        }
    }

    /**
     * ⚠️ And it is keyed per actor, not global: an impersonation or a console command iterating
     * accounts must not receive the first actor's answer.
     */
    public function testTwoActorsDoNotShareAnAnswer(): void
    {
        // A stub, not a mock: this case sets no expectation, and PHPUnit rightly notices the
        // difference — the bundle's configuration fails on notices.
        $permissions = self::createStub(PermissionDecisionService::class);
        $permissions->method('isGranted')->willReturnCallback(
            static fn (AclUserInterface $user): bool => 1 === $user->getId(),
        );

        $catalog = new EntityCatalog($permissions, null, [
            $this->provider(new ReportableEntity('label', 'crm:contact:read')),
        ]);

        self::assertNotSame([], $catalog->listFor($this->actor(1)));
        self::assertSame([], $catalog->listFor($this->actor(2)));
    }

    /**
     * ⚠️ The screen is not the only way in. A crafted POST naming an entity that was never offered
     * would otherwise reach the query builder.
     */
    public function testAnEntityOutsideTheCatalogueIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->catalog(granted: ['crm:contact:read'], enabled: ['crm.manage'])
            ->assertAllowed($this->actor(), \stdClass::class);
    }

    public function testAnAllowedEntityPassesTheHardGuard(): void
    {
        $catalog = $this->catalog(granted: ['crm:contact:read'], enabled: ['crm.manage']);

        $catalog->assertAllowed($this->actor(), \DateTimeImmutable::class);

        self::assertTrue($catalog->isAllowed($this->actor(), \DateTimeImmutable::class));
    }

    /**
     * @param list<string> $granted
     * @param list<string> $enabled
     */
    private function catalog(array $granted, array $enabled): EntityCatalog
    {
        $features = self::createStub(FeatureCheckerInterface::class);
        $features->method('isEnabled')->willReturnCallback(
            static fn (AclUserInterface $user, string $code): bool => \in_array($code, $enabled, true),
        );

        return new EntityCatalog(
            $this->permissions($granted),
            $features,
            [$this->provider(new ReportableEntity('label', 'crm:contact:read', 'crm.manage'))],
        );
    }

    /**
     * @param list<string> $granted
     */
    private function permissions(array $granted): PermissionDecisionService
    {
        $permissions = self::createStub(PermissionDecisionService::class);
        $permissions->method('isGranted')->willReturnCallback(
            static fn (AclUserInterface $user, string $code): bool => \in_array($code, $granted, true),
        );

        return $permissions;
    }

    private function provider(ReportableEntity $entity): ReportableEntityProviderInterface
    {
        return new readonly class($entity) implements ReportableEntityProviderInterface {
            public function __construct(private ReportableEntity $entity)
            {
            }

            public function entities(): array
            {
                return [\DateTimeImmutable::class => $this->entity];
            }
        };
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
