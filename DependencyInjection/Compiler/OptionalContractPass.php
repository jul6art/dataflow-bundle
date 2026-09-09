<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManagerInterface;
use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\ReportRunner;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reconciles the bundle's service graph with what the application actually has.
 *
 * Two jobs, one pass, because both answer the same question — "does this service exist here?" — and
 * two passes with identical reasoning is worse than one with two paragraphs.
 *
 * ## Why a compiler pass and not a check in the extension
 *
 * An extension runs before the other bundles have configured anything, so asking whether a service
 * exists there always answers no. By the time a pass runs, the application's own `services.yaml`
 * has been loaded and the question is answerable. Both `PurgeCommandPass` in `core-bundle` and
 * `OptionalContractPass` in `acl-bundle` exist for exactly this reason.
 *
 * ## The one contract that is optional here, and why it had to be
 *
 * ⚠️ **`FeatureCheckerInterface` is not bound in two of this bundle's three target applications.**
 * Feature flags are a multi-tenant SaaS concern; a single-product application has none, binds
 * nothing, and `acl-bundle` removes its own feature listener accordingly. Injecting the contract as
 * a plain constructor argument would therefore make the container **unresolvable at compile time**
 * in those applications — not a degraded feature, a boot failure.
 *
 * That defect has shipped twice in this ecosystem already, four months apart and identically:
 * `acl-bundle` in lot 12, then `admin-bundle` on 2026-08-22, both with `symfony/security-bundle`.
 * Writing the pass before the first consumer exists is the only way not to make it three.
 *
 * ## What absence MEANS, stated rather than implied
 *
 * With no checker bound, the `feature` half of a catalogue entry is **not enforced**; the
 * `permission` half always is. That reading is deliberately the same as `acl-bundle`'s — an
 * attribute with nothing to check against cannot refuse — and it is safe here because a catalogue
 * entry may legitimately declare no feature at all: on a single-product application, every entry
 * does.
 *
 * ⚠️ The permission half is **never** optional. A report engine without a per-entity authorisation
 * gate is a cross-tenant exfiltration tool, so `PermissionDecisionService` stays a hard argument —
 * it is registered unconditionally by `acl-bundle`, which every consumer installs.
 *
 * ⚠️ Since the wiring became explicit, `services.yaml` already asks for the checker with `@?`, so
 * this half is now the second belt rather than the only one. It stays because a consumer that
 * re-registers `EntityCatalog` with autowiring — to add its own decoration, say — loses the `@?`
 * and gets the compile-time failure back.
 *
 * ## And the Doctrine-dependent services, removed rather than left broken
 *
 * ⚠️ **This bundle requires `doctrine/orm`, the library, and deliberately not
 * `doctrine/doctrine-bundle`, the integration.** So `EntityManagerInterface` may genuinely have no
 * service behind it — in an application that only writes exports from arrays, which is a real and
 * supported use of the `Io/` half. Three services need it, and a bundle that assumed it would
 * refuse to boot there instead of simply offering less.
 *
 * ⚠️ **They are removed in dependency order, deepest first.** Removing `FieldCatalog` while
 * `ReportRunner` still references it leaves a dangling reference, and the message Symfony then
 * produces names the runner rather than the missing entity manager — which sends whoever reads it
 * looking in the wrong place.
 */
final class OptionalContractPass implements CompilerPassInterface
{
    /**
     * Deepest dependency first, so a removal never leaves a reference behind it.
     *
     * @var list<class-string>
     */
    private const array NEEDS_ENTITY_MANAGER = [
        ReportRunner::class,
        ImportRunner::class,
        FieldCatalog::class,
    ];

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $this->nullTheAbsentFeatureChecker($container);
        $this->removeWhatNeedsAnAbsentEntityManager($container);
    }

    private function nullTheAbsentFeatureChecker(ContainerBuilder $container): void
    {
        if (self::hasImplementation($container, FeatureCheckerInterface::class)) {
            return;
        }

        if ($container->hasDefinition(EntityCatalog::class)) {
            $container->getDefinition(EntityCatalog::class)->setArgument('$features', null);
        }
    }

    private function removeWhatNeedsAnAbsentEntityManager(ContainerBuilder $container): void
    {
        if (self::hasImplementation($container, EntityManagerInterface::class)) {
            return;
        }

        foreach (self::NEEDS_ENTITY_MANAGER as $id) {
            $container->removeDefinition($id);
        }
    }

    /**
     * ⚠️ An alias counts as much as a definition: registering an implementation under its own
     * class name and aliasing the interface to it is the ordinary Symfony pattern, and reading
     * that as "nothing registered" would disable the feature gate on every correctly wired
     * application.
     */
    private static function hasImplementation(ContainerBuilder $container, string $interface): bool
    {
        return $container->hasAlias($interface) || $container->hasDefinition($interface);
    }
}
