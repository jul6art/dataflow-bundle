<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Web;

use Jul6Art\DataflowBundle\DataflowBundle;
use Jul6Art\DataflowBundle\Import\Web\ImportController;
use Jul6Art\DataflowBundle\Import\Web\ImportRoutes;
use Jul6Art\DataflowBundle\Tests\Import\Web\Fixtures\FruitImportDefinition;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

/**
 * A kernel with the import screens on: two fruit imports routed by name, two on a route with `{entity}`.
 */
final class ScreensKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $import the "dataflow.import" configuration of the scenario
     */
    public function __construct(private readonly array $import = ['enabled' => true])
    {
        parent::__construct('test', false);
    }

    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new DataflowBundle();
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures';
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

    public function importDirectory(): string
    {
        return $this->buildDir().'/import';
    }

    public function fruitList(): Response
    {
        return new Response('the fruits');
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'translator' => ['default_path' => __DIR__.'/Fixtures/translations', 'fallbacks' => ['en']],
            'router' => ['utf8' => true],
        ]);
        $container->extension('twig', ['default_path' => __DIR__.'/Fixtures/templates', 'strict_variables' => true]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => [
                'importer' => ['password' => 'x', 'roles' => ['ROLE_IMPORTER']],
                'visitor' => ['password' => 'x', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
        ]);
        $container->extension('dataflow', ['import' => $this->import + ['directory' => $this->importDirectory(), 'layout' => 'layout.html.twig']]);

        $services = $container->services();
        $services->set('fruit', FruitImportDefinition::class)->public()
            ->args(['fruit', false, null, null, 'fruit.import', ['report' => 'fruit_report.html.twig']])
            ->tag('dataflow.import', ['key' => 'fruit', 'priority' => 20]);
        $services->set('small_fruit', FruitImportDefinition::class)->public()
            ->args(['small_fruit', false, 64, inline_service(ImportRoutes::class)->args(['small_upload', 'small_mapping', 'small_run'])])
            ->tag('dataflow.import', ['key' => 'small_fruit']);
        $services->set('previewed_fruit', FruitImportDefinition::class)->public()
            ->args(['previewed_fruit', true, null, inline_service(ImportRoutes::class)->args(['previewed_upload', 'previewed_mapping', 'previewed_run'])])
            ->tag('dataflow.import', ['key' => 'previewed_fruit']);
        $services->set('many_fruit', FruitImportDefinition::class)->public()
            ->args(['many_fruit', false, null, inline_service(ImportRoutes::class)->args(['many_upload', 'many_mapping', 'many_run'])])
            ->tag('dataflow.import', ['key' => 'many_fruit', 'priority' => 10]);
        $services->set('many_veg', FruitImportDefinition::class)->public()
            ->args(['many_veg', false, null, inline_service(ImportRoutes::class)->args(['many_upload', 'many_mapping', 'many_run']), 'veg.import'])
            ->tag('dataflow.import', ['key' => 'many_veg', 'priority' => 5]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('fruit_list', '/fruits')->controller('kernel::fruitList');

        foreach (['fruit' => 'fruit', 'small' => 'small_fruit', 'previewed' => 'previewed_fruit'] as $name => $key) {
            $routes->add($name.'_upload', '/'.$name.'/import')->controller([ImportController::class, 'upload'])->methods(['GET', 'POST'])->defaults(['_import' => $key]);
            $routes->add($name.'_mapping', '/'.$name.'/import/mapping')->controller([ImportController::class, 'mapping'])->methods(['GET'])->defaults(['_import' => $key]);
            $routes->add($name.'_run', '/'.$name.'/import/run')->controller([ImportController::class, 'run'])->methods(['POST'])->defaults(['_import' => $key]);
            $routes->add($name.'_template', '/'.$name.'/import/template')->controller([ImportController::class, 'template'])->methods(['GET'])->defaults(['_import' => $key]);
        }

        $requirements = ['entity' => 'many_fruit|many_veg'];
        $routes->add('many_upload', '/many/{entity}')->controller([ImportController::class, 'upload'])->methods(['GET', 'POST'])->requirements($requirements);
        $routes->add('many_mapping', '/many/{entity}/mapping')->controller([ImportController::class, 'mapping'])->methods(['GET'])->requirements($requirements);
        $routes->add('many_run', '/many/{entity}/run')->controller([ImportController::class, 'run'])->methods(['POST'])->requirements($requirements);
    }

    /**
     * ⚠️ Keyed on the scenario AND on the bundle's source: the kernel runs without debug, so a compiled container or
     * a Twig cache never checks its freshness — editing a template would be tested against the previous version.
     */
    private function buildDir(): string
    {
        $latest = 0;
        $root = \dirname(__DIR__, 3);

        foreach (['Import/Web', 'Resources', 'DependencyInjection', 'Tests/Import/Web'] as $directory) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo) {
                    $latest = max($latest, $file->getMTime());
                }
            }
        }

        return sys_get_temp_dir().'/jul6art-dataflow-import-screens/'.md5(serialize($this->import).$latest);
    }
}
