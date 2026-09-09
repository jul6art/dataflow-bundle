<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\HeaderInspector;
use Jul6Art\DataflowBundle\Port\LimitsProviderInterface;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The first test to write, and the one that keeps paying: a real container, built with the bundle
 * registered.
 *
 * It catches what no unit test can — a services.yaml that does not parse, a reference to a service
 * that does not exist, a configuration node the extension reads under another name. Every one of
 * those is invisible until something boots.
 */
#[CoversNothing]
final class ContainerTest extends AbstractFunctionalTestCase
{
    public function testTheBundleBoots(): void
    {
        self::assertTrue($this->boot()->getParameter('dataflow.enabled'));
    }

    /**
     * `enabled: false` must leave the bundle installed and inert — an application should be able
     * to switch it off without uninstalling it, and without its optional dependencies becoming
     * required.
     */
    public function testItCanBeDisabled(): void
    {
        self::assertFalse($this->boot('test', ['enabled' => false])->hasParameter('dataflow.enabled'));
    }

    /**
     * ⚠️ And "inert" means NO definitions, not merely no parameter. Loading the services and then
     * returning early left definitions referencing `%dataflow.translation_domain%`, which the early
     * return never sets. That happened to compile — every one of those services is private and
     * unreferenced, so the container removed them before resolving parameters — and would have
     * become a boot failure for the first application to inject one.
     */
    public function testDisabledMeansNoServicesAtAll(): void
    {
        $container = $this->boot('test', ['enabled' => false]);

        self::assertFalse($container->has(ReportSpecInterpreter::class));
        self::assertFalse($container->has(HeaderInspector::class));
        self::assertFalse($container->has(LimitsProviderInterface::class));
        self::assertFalse($container->hasParameter('dataflow.limits.export_rows'));
    }
}
