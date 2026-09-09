<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle;

use Jul6Art\DataflowBundle\DependencyInjection\Compiler\OptionalContractPass;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use Jul6Art\DataflowBundle\Report\Catalog\ReportableEntityProviderInterface;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerInterface;
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

        // ⚠️ Autoconfiguration is declared here and NOT relied on inside the bundle: it applies to
        // the APPLICATION's services, which do have `autoconfigure: true`, so a consumer's provider
        // or transformer is picked up by writing the class and nothing else. The bundle's own
        // services are tagged explicitly in `services.yaml`, because a consumer that turns
        // autoconfiguration off for `vendor/` — which it should — would otherwise lose them
        // silently.
        $container->registerForAutoconfiguration(ReportableEntityProviderInterface::class)
            ->addTag('dataflow.reportable_entity_provider');

        $container->registerForAutoconfiguration(ValueTransformerInterface::class)
            ->addTag('dataflow.value_transformer');

        // A consumer's own writer or reader — an application-specific fixed-width format, a
        // customer's imposed layout — joins the tagged iterator by existing.
        $container->registerForAutoconfiguration(TabularWriterInterface::class)
            ->addTag('dataflow.tabular_writer');

        $container->registerForAutoconfiguration(TabularReaderInterface::class)
            ->addTag('dataflow.tabular_reader');
    }
}
