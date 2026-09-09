<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection;

use Jul6Art\DataflowBundle\Port\ConfiguredLimitsProvider;
use Jul6Art\DataflowBundle\Port\Limits;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
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
class DataflowExtension extends Extension
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

        $domain = $config['translation_domain'] ?? 'dataflow';

        if (!\is_string($domain) || '' === $domain) {
            throw new \LogicException('The "translation_domain" node must be a non-empty string; the configuration tree guarantees it.');
        }

        $container->setParameter('dataflow.translation_domain', $domain);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $limits = $config['limits'] ?? [];

        if (!\is_array($limits)) {
            throw new \LogicException('The "limits" node must be an array; the configuration tree guarantees it.');
        }

        $this->publishLimits($container, $limits);
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
    private function publishLimits(ContainerBuilder $container, array $limits): void
    {
        $arguments = [];

        foreach (self::LIMIT_NODES as $node => $argument) {
            $value = $limits[$node] ?? null;

            if (!\is_int($value)) {
                // Unreachable through configuration: every node is an `integerNode` with a default.
                // Reachable by editing one list and not the other, which is the point.
                throw new \LogicException(\sprintf('Limit "%s" is missing from the configuration tree.', $node));
            }

            // Both, and that is the whole point: the object is what services read, the parameter is
            // what an application's own YAML reads.
            $container->setParameter('dataflow.limits.'.$node, $value);
            $arguments[$argument] = $value;
        }

        $container->getDefinition(ConfiguredLimitsProvider::class)
            ->setArgument('$limits', new Definition(Limits::class, $arguments));
    }
}
