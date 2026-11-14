<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/** A route and its parameters. */
final readonly class ImportLink
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $route,
        public array $parameters = [],
    ) {
    }
}
