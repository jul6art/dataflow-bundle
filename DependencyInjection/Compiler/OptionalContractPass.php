<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection\Compiler;

use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Nulls the optional contracts the application did not implement.
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
 */
final class OptionalContractPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (self::hasImplementation($container, FeatureCheckerInterface::class)) {
            return;
        }

        if ($container->hasDefinition(EntityCatalog::class)) {
            $container->getDefinition(EntityCatalog::class)->setArgument('$features', null);
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
