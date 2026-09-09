<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle;

use Jul6Art\DataflowBundle\DependencyInjection\Compiler\OptionalContractPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Tabular import, export and report engine for Symfony.
 *
 * Registering a compiler pass? Override `build()` here — a pass is how you check that a service
 * the application may or may not have actually exists, which an extension cannot do (extensions
 * run before the other bundles have had their say):
 *
 * ```php
 * #[\Override]
 * public function build(ContainerBuilder $container): void
 * {
 *     parent::build($container);
 *
 *     $container->addCompilerPass(new SomethingOptionalPass());
 * }
 * ```
 */
class DataflowBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // ⚠️ A pass, not a check in the extension: an extension runs before the other bundles have
        // configured anything, so `$container->has(...)` always answers no there. This one nulls
        // the feature checker in the applications that have no feature system — without it their
        // container is unresolvable at COMPILE time, which is a boot failure and not a degraded
        // feature. Two bundles of this ecosystem shipped that defect before, four months apart.
        $container->addCompilerPass(new OptionalContractPass());
    }
}
