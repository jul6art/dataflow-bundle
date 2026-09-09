<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\HeaderInspector;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use Jul6Art\DataflowBundle\Port\ConfiguredLimitsProvider;
use Jul6Art\DataflowBundle\Port\ExportAuditorInterface;
use Jul6Art\DataflowBundle\Port\LimitsProviderInterface;
use Jul6Art\DataflowBundle\Port\NullExportAuditor;
use Jul6Art\DataflowBundle\Port\ReportDefinitionStoreInterface;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\ReportRunner;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * What the container actually contains — which is the half of a bundle a unit test cannot reach.
 *
 * ⚠️ Every assertion here would have caught a defect that is invisible until something boots: a
 * service id spelled from memory, an argument named `$domain` where the constructor says
 * `$translationDomain`, a `%parameter%` the extension forgot to set, a Doctrine-dependent service
 * left registered in an application that has no entity manager.
 */
#[CoversNothing]
final class WiringTest extends AbstractFunctionalTestCase
{
    /**
     * The three that need nothing at all, so nothing can stop them being available.
     */
    public function testTheDependencyFreeServicesAreRegistered(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(ReportSpecInterpreter::class, $container->get(ReportSpecInterpreter::class));
        self::assertInstanceOf(HeaderInspector::class, $container->get(HeaderInspector::class));
        self::assertInstanceOf(TabularResponseFactory::class, $container->get(TabularResponseFactory::class));
        self::assertInstanceOf(CsvReader::class, $container->get(CsvReader::class));
    }

    /**
     * ⚠️ The two ports that HAVE a sensible default resolve to it, so an application that binds
     * nothing still has a working bundle.
     */
    public function testEachOptionalPortResolvesToItsDocumentedDefault(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(ConfiguredLimitsProvider::class, $container->get(LimitsProviderInterface::class));
        self::assertInstanceOf(NullExportAuditor::class, $container->get(ExportAuditorInterface::class));
    }

    /**
     * ⚠️ And the one that has NO default is not aliased to anything. A store invented in a bundle
     * would force one tenancy model on three applications, so its absence is the design — asserted,
     * because an absence nobody pinned is an absence somebody fills in by accident.
     */
    public function testTheReportStoreIsDeliberatelyUnbound(): void
    {
        self::assertFalse($this->boot()->has(ReportDefinitionStoreInterface::class));
    }

    /**
     * ⚠️ The fix for a literal written twice. Every ceiling is a parameter, so an application's own
     * `rate_limiter.yaml` can read the SAME number the bundle's services read.
     */
    public function testEveryCeilingIsAlsoAContainerParameter(): void
    {
        $container = $this->boot();

        self::assertSame(1000, $container->getParameter('dataflow.limits.report_rows'));
        self::assertSame(50000, $container->getParameter('dataflow.limits.export_rows'));
        self::assertSame(30, $container->getParameter('dataflow.limits.exports_per_hour'));
        self::assertSame(10000, $container->getParameter('dataflow.limits.export_rows_per_hour'));
        self::assertSame(10000, $container->getParameter('dataflow.limits.import_rows'));
        self::assertSame(5, $container->getParameter('dataflow.limits.imports_per_hour'));
        self::assertSame(2, $container->getParameter('dataflow.limits.field_max_depth'));
    }

    /**
     * ⚠️ And configuring one changes BOTH the parameter and what the provider answers. Two readers
     * of one value is the whole point; a test that checked only the parameter would let them drift.
     */
    public function testConfiguringACeilingChangesTheParameterAndTheProvider(): void
    {
        $container = $this->boot('test', ['limits' => ['export_rows' => 250, 'export_rows_per_hour' => 777]]);

        self::assertSame(250, $container->getParameter('dataflow.limits.export_rows'));

        $provider = $container->get(LimitsProviderInterface::class);
        self::assertInstanceOf(LimitsProviderInterface::class, $provider);

        $limits = $provider->limitsFor();

        self::assertSame(250, $limits->exportRowLimit);
        self::assertSame(777, $limits->exportRowsPerHour);
        self::assertSame(1000, $limits->reportRowLimit, 'An untouched ceiling keeps its default.');
    }

    /**
     * ⚠️ Three services need `EntityManagerInterface`, and this bundle requires `doctrine/orm` —
     * the library — while deliberately NOT requiring `doctrine-bundle` — the integration. So an
     * application that only writes exports from arrays has no entity manager service, and a bundle
     * that assumed one would refuse to boot there instead of offering less.
     */
    public function testTheDoctrineDependentServicesAreAbsentWithoutAnEntityManager(): void
    {
        $container = $this->boot();

        self::assertTrue($container->has(EntityCatalog::class), 'The entity catalogue needs no ORM…');
        self::assertFalse($container->has(FieldCatalog::class), '…the field catalogue does.');
        self::assertFalse($container->has(ReportRunner::class));
        self::assertFalse($container->has(ImportRunner::class));
    }

    public function testAndTheyArePresentWithOne(): void
    {
        $container = $this->boot(withOrm: true);

        self::assertInstanceOf(FieldCatalog::class, $container->get(FieldCatalog::class));
        self::assertInstanceOf(ReportRunner::class, $container->get(ReportRunner::class));
        self::assertInstanceOf(ImportRunner::class, $container->get(ImportRunner::class));
    }

    /**
     * ⚠️ The catalogue must be usable with NO feature checker bound: two of the three target
     * applications bind none, and a hard reference would make their container unresolvable at
     * compile time — a boot failure, not a degraded feature.
     */
    public function testTheEntityCatalogueBuildsWithoutAFeatureChecker(): void
    {
        self::assertInstanceOf(EntityCatalog::class, $this->boot()->get(EntityCatalog::class));
    }
}
