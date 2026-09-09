<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's configuration tree.
 *
 * Write an `->info()` on every node: it is what `config:dump-reference` shows, and it is the only
 * documentation a reader gets before opening the code.
 *
 * > ⚠️ **A node that decides something at compile time cannot be an env var.** `%env(bool:X)%`
 * > reaches a `booleanNode()` as the placeholder *string* and the config layer rejects it. Use a
 * > plain value for anything that gates service registration, and keep env vars for values passed
 * > through to a service at runtime (a `scalarNode` argument).
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dataflow');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Registers the bundle\'s services. false leaves it installed and inert.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('translation_domain')
                    ->info('Catalogue the bundle\'s own keys are looked up in. Never "messages".')
                    ->defaultValue('dataflow')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('stimulus_identifier')
                    ->info('The Stimulus identifier the report builder answers to. It decides the data-attribute prefix the shipped partial emits, so it has to match how the application registered the controller — a build that derives identifiers from a path gives "dataflow--report-builder" to a file living in assets/controllers/dataflow/.')
                    ->defaultValue('dataflow--report-builder')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('limits')
                    ->info('Ceilings applied when no LimitsProviderInterface is bound. Every value is also a container parameter, so an application\'s own YAML can read the SAME number.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('report_rows')
                            ->info('Rows a report run returns when the caller asks for no limit.')
                            ->defaultValue(1000)
                            ->min(1)
                        ->end()
                        ->integerNode('export_rows')
                            ->info('Rows one export may contain.')
                            ->defaultValue(50000)
                            ->min(1)
                        ->end()
                        ->integerNode('exports_per_hour')
                            ->info('Exports one actor may run per hour.')
                            ->defaultValue(30)
                            ->min(1)
                        ->end()
                        ->integerNode('export_rows_per_hour')
                            ->info('Row budget one actor may export per hour. Read this as %dataflow.limits.export_rows_per_hour% from your rate_limiter.yaml so the bucket size and the arithmetic that reads it cannot drift apart.')
                            ->defaultValue(10000)
                            ->min(1)
                        ->end()
                        ->integerNode('import_rows')
                            ->info('Rows one imported file may contain.')
                            ->defaultValue(10000)
                            ->min(1)
                        ->end()
                        ->integerNode('imports_per_hour')
                            ->info('Imports one actor may run per hour.')
                            ->defaultValue(5)
                            ->min(1)
                        ->end()
                        ->integerNode('field_max_depth')
                            ->info('How many toOne relations the field catalogue walks. 0 offers the root entity only, which is a legitimate choice.')
                            ->defaultValue(2)
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
