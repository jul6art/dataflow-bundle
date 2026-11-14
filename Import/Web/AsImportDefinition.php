<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/**
 * Registers an autoconfigured service as an import, under its key: `#[AsImportDefinition('customer')]`.
 *
 * ⚠️ The priority orders the imports a screen lists: a site needs its customer, an asset its site — the order to
 * follow is said on the screen rather than guessed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsImportDefinition
{
    public function __construct(
        public string $key,
        public int $priority = 0,
    ) {
    }
}
