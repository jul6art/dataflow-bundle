<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection;

use Jul6Art\DataflowBundle\Port\ConfiguredLimitsProvider;
use Jul6Art\DataflowBundle\Port\Limits;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Wires the bundle's services and turns its configuration into something the services can read.
 *
 * Two rules this ecosystem arrived at the hard way:
 *
 * 1. **A brick whose dependency is optional is registered conditionally**, from here, guarded by
 *    `class_exists()` / `interface_exists()` — never by an attribute on the class. An
 *    `#[AsDecorator]` or `#[AsDoctrineListener]` on a vendor class is only honoured if the
 *    application autoconfigures `vendor/`, which it should not, and it makes the class
 *    unloadable when the package is absent.
 * 2. **A service that needs another *service* to exist is checked in a compiler pass**, not
 *    here: an extension runs before the other bundles have configured anything, so
 *    `$container->has('some.service')` is always false at this point.
 */
class DataflowExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Configuration node → {@see Limits} constructor argument.
     *
     * @var array<string, string>
     */
    private const array LIMIT_NODES = [
        'report_rows' => '$reportRowLimit',
        'export_rows' => '$exportRowLimit',
        'exports_per_hour' => '$exportsPerHour',
        'export_rows_per_hour' => '$exportRowsPerHour',
        'import_rows' => '$importRowLimit',
        'imports_per_hour' => '$importsPerHour',
        'field_max_depth' => '$fieldMaxDepth',
    ];

    /**
     * Hands the shipped partials their two settings, without a Twig extension.
     *
     * ⚠️ `hasExtension()` works HERE and `has()` does not: other bundles' EXTENSIONS are all
     * registered before any `load()` runs, whereas their services are not — which is the same
     * distinction that puts service checks in a compiler pass.
     *
     * ⚠️ Globals rather than an extension with two functions: `twig/twig` is a suggested package,
     * so a Twig extension class would have to be registered conditionally anyway, and a global is
     * one line the consumer can also override in their own `twig.yaml`.
     */
    private function exposeTwigGlobals(ContainerBuilder $container, string $stimulus, string $domain): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $container->prependExtensionConfig('twig', [
            'globals' => [
                'dataflow_stimulus' => $stimulus,
                'dataflow_domain' => $domain,
            ],
        ]);
    }

    /**
     * Publishes the ceilings as container parameters, and the two Twig globals.
     *
     * ## Why `prepend()` and not `load()`
     *
     * ⚠️ **A parameter another bundle's YAML interpolates has to exist before that bundle's
     * extension loads**, and extensions load in bundle-registration order. Set from `load()`,
     * `%dataflow.limits.export_rows_per_hour%` was undefined by the time FrameworkBundle read a
     * `rate_limiter` whose `limit` referenced it — "You have requested a non-existent parameter …
     * while loading extension framework". And it would have *worked* for a consumer that happened
     * to register this bundle before FrameworkBundle, which is the worst kind of dependency: an
     * ordering nobody declared.
     *
     * `prepend()` runs for every bundle before ANY `load()`, so this is the only hook where the
     * answer does not depend on the order. The configuration is read with `getExtensionConfig()`,
     * since `load()`'s `$configs` are not handed to `prepend()`.
     */
    #[\Override]
    public function prepend(ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(
            new Configuration(),
            $container->getExtensionConfig($this->getAlias()),
        );

        if (false === ($config['enabled'] ?? true)) {
            return;
        }

        $limits = $config['limits'] ?? [];

        if (!\is_array($limits)) {
            throw new \LogicException('The "limits" node must be an array; the configuration tree guarantees it.');
        }

        foreach (self::LIMIT_NODES as $node => $argument) {
            $value = $limits[$node] ?? null;

            if (!\is_int($value)) {
                throw new \LogicException(\sprintf('Limit "%s" is missing from the configuration tree.', $node));
            }

            $container->setParameter('dataflow.limits.'.$node, $value);
        }

        $container->setParameter('dataflow.translation_domain', self::domain($config));
        $container->setParameter('dataflow.stimulus_identifier', self::stimulus($config));

        $this->exposeTwigGlobals($container, self::stimulus($config), self::domain($config));
    }

    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // ⚠️ The services are loaded AFTER the check, not before. Loaded first, `enabled: false`
        // leaves definitions referencing `%dataflow.translation_domain%` — a parameter the early
        // return never sets. Today that happens to compile, because every one of those services is
        // private and unreferenced so the container removes them before resolving parameters; the
        // first application to inject one turns "installed and inert" into a boot failure. Inert
        // has to mean no definitions at all.
        if (false === ($config['enabled'] ?? true)) {
            return;
        }

        // Exposed as a container parameter so an application can branch on it, and so
        // `debug:container --parameter` tells the truth about what is active.
        $container->setParameter('dataflow.enabled', true);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $limits = $config['limits'] ?? [];

        if (!\is_array($limits)) {
            throw new \LogicException('The "limits" node must be an array; the configuration tree guarantees it.');
        }

        // ⚠️ The parameters are already published by `prepend()`; what is left for `load()` is the
        // service argument, which needs the definitions that were just loaded.
        $this->configureLimitsProvider($container, $limits);
    }

    /**
     * Turns the `limits` node into one {@see Limits} object AND one parameter per value.
     *
     * ⚠️ **Both, and that is the whole point.** The object is what services read; the parameters are
     * what an application's own YAML reads. The reference application wrote its row budget `10000`
     * twice — once as a `rate_limiter.yaml` bucket size, once in the PHP that subtracted from it —
     * with nothing linking them, so changing the YAML made the arithmetic wrong in silence. With
     * `%dataflow.limits.export_rows_per_hour%` in both places there is one number.
     *
     * ⚠️ The `Limits` object is an INLINE definition rather than a registered service: it is
     * configuration, not a collaborator, and putting a value object in `debug:container` invites
     * someone to inject it directly and bypass the port that exists to be replaced.
     *
     * ⚠️ The node names and the constructor arguments are listed side by side ON PURPOSE. They
     * differ — snake_case in YAML, camelCase in PHP — and a mapping spread across two lists is how
     * a renamed node silently stops being read. Here a mismatch throws at compile time.
     *
     * @param array<array-key, mixed> $limits
     */
    private function configureLimitsProvider(ContainerBuilder $container, array $limits): void
    {
        $arguments = [];

        foreach (self::LIMIT_NODES as $node => $argument) {
            $value = $limits[$node] ?? null;

            if (!\is_int($value)) {
                // Unreachable through configuration: every node is an `integerNode` with a default.
                // Reachable by editing one list and not the other, which is the point.
                throw new \LogicException(\sprintf('Limit "%s" is missing from the configuration tree.', $node));
            }

            $arguments[$argument] = $value;
        }

        $container->getDefinition(ConfiguredLimitsProvider::class)
            ->setArgument('$limits', new Definition(Limits::class, $arguments));
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function domain(array $config): string
    {
        $domain = $config['translation_domain'] ?? 'dataflow';

        if (!\is_string($domain) || '' === $domain) {
            throw new \LogicException('The "translation_domain" node must be a non-empty string; the configuration tree guarantees it.');
        }

        return $domain;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function stimulus(array $config): string
    {
        $stimulus = $config['stimulus_identifier'] ?? 'dataflow--report-builder';

        if (!\is_string($stimulus) || '' === $stimulus) {
            throw new \LogicException('The "stimulus_identifier" node must be a non-empty string; the configuration tree guarantees it.');
        }

        return $stimulus;
    }
}
