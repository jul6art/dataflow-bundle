<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\DataflowBundle\DataflowBundle;
use Jul6Art\DataflowBundle\Import\HeaderInspector;
use Jul6Art\DataflowBundle\Import\ImportRunner;
use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;
use Jul6Art\DataflowBundle\Io\Reader\CsvReader;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;
use Jul6Art\DataflowBundle\Port\ExportAuditorInterface;
use Jul6Art\DataflowBundle\Port\LimitsProviderInterface;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\ReportRunner;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Minimal application kernel used by the functional tests.
 *
 * A bundle is only really proven by booting a container: half of what goes wrong in a bundle is
 * wiring, not logic — a service registered under the wrong condition, a decoration that does not
 * take, a tag Doctrine never sees. None of that shows up in a unit test.
 *
 * The optional pieces are flags rather than separate kernels so a test can ask for exactly the
 * environment its scenario needs, and no more: booting Doctrine to check a configuration node
 * costs a second per test for nothing.
 */
final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $bundleConfig configuration for the "dataflow" extension
     * @param bool                 $withOrm      registers DoctrineBundle on in-memory SQLite,
     *                                           mapped on Tests/Fixtures/Entity
     * @param bool                 $withTwig     registers TwigBundle, so the shipped partials can
     *                                           be RENDERED rather than merely parsed
     * @param string               $uniqueId     keys the build directory, so two scenarios never
     *                                           share a compiled container while identical ones
     *                                           still reuse the cache
     */
    public function __construct(
        string $environment,
        private readonly array $bundleConfig = [],
        private readonly bool $withOrm = false,
        private readonly bool $withTwig = false,
        private readonly string $uniqueId = 'default',
    ) {
        // Debug mode installs Symfony's error handler and never removes it, which PHPUnit
        // rightly reports as leaking global state.
        parent::__construct($environment, false);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        if ($this->withOrm) {
            yield new DoctrineBundle();
        }

        if ($this->withTwig) {
            yield new TwigBundle();
        }

        yield new DataflowBundle();
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->configure(...));
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->buildDir().'/cache';
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->buildDir().'/log';
    }

    /**
     * Marks the services the tests need to reach.
     *
     * Symfony inlines or removes private services, so `$container->get()` on one throws "has been
     * removed or inlined" — a message that reads like a bug in the bundle and is not. Listing them
     * here is the least intrusive fix; the alternative, making them public in the extension, would
     * change what the bundle exposes to real applications for the sake of a test.
     *
     * Beware: an id can change during compilation. A decorated service is renamed, so asserting on
     * `some.service` after decorating it tells you nothing — assert on what was *injected*
     * instead.
     */
    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                $exposed = [
                    'doctrine.orm.default_entity_manager',
                    'event_dispatcher',
                    'request_stack',
                    'security.token_storage',
                    'validator',
                    'translator',
                    'twig',
                    // The bundle's own services. Exposing them is what makes "installed and
                    // inert" observable: private definitions are removed when nothing references
                    // them, so `has()` on an unexposed one answers false whether the bundle
                    // registered it or not.
                    ReportSpecInterpreter::class,
                    HeaderInspector::class,
                    TabularResponseFactory::class,
                    CsvReader::class,
                    CsvWriter::class,
                    EntityCatalog::class,
                    FieldCatalog::class,
                    ReportRunner::class,
                    ImportRunner::class,
                    ValueTransformerChain::class,
                    LimitsProviderInterface::class,
                    ExportAuditorInterface::class,
                ];

                foreach ($container->getDefinitions() as $id => $definition) {
                    if (\in_array($id, $exposed, true)) {
                        $definition->setPublic(true);
                    }
                }

                foreach ($container->getAliases() as $id => $alias) {
                    if (\in_array($id, $exposed, true)) {
                        $alias->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 100);
    }

    private function buildDir(): string
    {
        return \sprintf('%s/jul6art-dataflow-bundle-tests/%s/%s', sys_get_temp_dir(), $this->uniqueId, $this->environment);
    }

    private function configure(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'jul6art-dataflow-bundle-tests',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // The import runner validates every mapped entity before persisting it, so the
            // container has to carry a validator — attributes on, since that is how a consumer's
            // entities declare their constraints.
            'validation' => ['enabled' => true, 'enable_attributes' => true],
            // `BoolValueTransformer` renders two catalogue keys, so the translator is a hard
            // dependency of the bundle rather than an optional one — and a container without it
            // would not compile.
            'translator' => ['default_path' => '%kernel.project_dir%/Resources/translations'],
        ]);

        $this->registerAclStandIn($container);

        if ($this->withOrm) {
            $this->configureDoctrine($container);
        }

        if ($this->withTwig) {
            // The bundle's own `Resources/views` is registered as `@Dataflow` by TwigBundle, and
            // its `Resources/translations` catalogue is picked up the same way — so nothing has to
            // be pointed at here, which is exactly what a consumer gets.
            $container->loadFromExtension('twig', ['strict_variables' => true]);
        }

        $container->loadFromExtension('dataflow', $this->bundleConfig);
    }

    /**
     * Registers the one `acl-bundle` service this bundle references, without booting `acl-bundle`.
     *
     * ⚠️ A stand-in and not the real bundle, deliberately: `acl-bundle` requires
     * `symfony/security-bundle`, which needs a firewall configuration to boot, and none of that
     * proves anything about THIS bundle's wiring. What has to be right is the service **id** — a
     * typo there is exactly the class of defect a container test exists to catch — so the id and
     * the class are the real ones, and only the collaborators are left out.
     */
    private function registerAclStandIn(ContainerBuilder $container): void
    {
        $container
            ->register(PermissionDecisionService::class, PermissionDecisionService::class)
            ->setArguments(['ROLE_ORGANIZATION_ADMIN', null, true])
            ->setPublic(true);
    }

    /**
     * The whole `Tests/Fixtures/Entity` directory is mapped, so a new fixture entity is picked up
     * without touching this method.
     */
    private function configureDoctrine(ContainerBuilder $container): void
    {
        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'orm' => [
                'controller_resolver' => ['auto_mapping' => false],
                'mappings' => [
                    'DataflowBundleTests' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Jul6Art\DataflowBundle\\Tests\\Fixtures\\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }
}
