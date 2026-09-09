<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Security\PermissionDecisionService;

/**
 * Which entities a given actor may report on.
 *
 * Aggregates every {@see ReportableEntityProviderInterface} and applies the two gates: the tenant
 * feature, then the actor's permission.
 *
 * ## Memoised, twice, and the second one is the fix
 *
 * ⚠️ The version this replaces memoised the aggregated catalogue but **not the per-actor answer**.
 * Its runner called a path check once per column and once per filter; each of those walked the
 * Doctrine metadata and, for every traversed relation whose target was in the catalogue, re-ran a
 * feature check and a permission check for **all** entries. A ten-column, three-filter report
 * therefore replayed the whole thing thirteen times, and nothing measured it.
 *
 * The per-actor cache is keyed on the actor's id, so two actors in one request — an impersonation,
 * a console command iterating accounts — do not share an answer.
 *
 * ⚠️ **It is a request-lifetime cache, not a shared one.** Granting a permission mid-request will
 * not be seen. That is correct for a report run and would be wrong for a permission screen, which
 * is why this class is not the one an ACL admin page should use.
 */
final class EntityCatalog
{
    /** @var array<class-string, ReportableEntity>|null */
    private ?array $catalog = null;

    /** @var array<array-key, array<class-string, ReportableEntity>> */
    private array $allowed = [];

    /**
     * @param iterable<ReportableEntityProviderInterface> $providers
     * @param FeatureCheckerInterface|null                $features  null when the application has
     *                                                               no feature system; see
     *                                                               {@see \Jul6Art\DataflowBundle\DependencyInjection\Compiler\OptionalContractPass}
     */
    public function __construct(
        private readonly PermissionDecisionService $permissions,
        private readonly ?FeatureCheckerInterface $features = null,
        private readonly iterable $providers = [],
    ) {
    }

    /**
     * @return array<class-string, ReportableEntity>
     */
    public function listFor(AclUserInterface $actor): array
    {
        $key = (string) ($actor->getId() ?? 'anonymous');

        if (isset($this->allowed[$key])) {
            return $this->allowed[$key];
        }

        $allowed = [];

        foreach ($this->catalog() as $fqcn => $entity) {
            if (!$this->isFeatureEnabled($actor, $entity)) {
                continue;
            }

            if (!$this->permissions->isGranted($actor, $entity->permission)) {
                continue;
            }

            $allowed[$fqcn] = $entity;
        }

        return $this->allowed[$key] = $allowed;
    }

    /**
     * The hard guard the runner calls before touching a query builder.
     *
     * ⚠️ It exists because the screen is not the only way in. A crafted POST naming an entity that
     * was never offered would otherwise reach the query builder, and the catalogue is the only
     * thing that knows the entity was not on the menu.
     *
     * @throws \DomainException
     */
    public function assertAllowed(AclUserInterface $actor, string $fqcn): void
    {
        if (!isset($this->listFor($actor)[$fqcn])) {
            throw new \DomainException(\sprintf('"%s" is not reportable for this actor.', $fqcn));
        }
    }

    public function isAllowed(AclUserInterface $actor, string $fqcn): bool
    {
        return isset($this->listFor($actor)[$fqcn]);
    }

    /**
     * What the catalogue declares about an entity, **regardless of any actor**.
     *
     * ⚠️ The distinction from {@see self::isAllowed()} matters and is easy to get backwards. The
     * field catalogue traverses a relation only if it may; to decide, it first has to know whether
     * the target is a reportable entity AT ALL. A referential — a country, a unit, a tax rate — is
     * not in the catalogue and is traversed freely, because requiring one entry per look-up table
     * would make the catalogue unusable. Only a target the catalogue KNOWS is then gated on the
     * actor.
     *
     * Answering `null` therefore means "not a reportable entity", never "refused".
     */
    public function metaFor(string $fqcn): ?ReportableEntity
    {
        return $this->catalog()[$fqcn] ?? null;
    }

    /**
     * ⚠️ No checker bound means the feature half is not enforced — the same reading `acl-bundle`
     * applies to `#[RequiresFeature]`, and the only one that lets a single-product application use
     * this bundle at all. The permission half above is never optional.
     */
    private function isFeatureEnabled(AclUserInterface $actor, ReportableEntity $entity): bool
    {
        if (null === $entity->feature || null === $this->features) {
            return true;
        }

        return $this->features->isEnabled($actor, $entity->feature);
    }

    /**
     * @return array<class-string, ReportableEntity>
     */
    private function catalog(): array
    {
        if (null !== $this->catalog) {
            return $this->catalog;
        }

        $catalog = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->entities() as $fqcn => $entity) {
                $catalog[$fqcn] = $entity;
            }
        }

        return $this->catalog = $catalog;
    }
}
