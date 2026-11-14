<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/**
 * Who runs the import, and from which route: the account, and the route's parameters — the `{id}` of the object an
 * import belongs to (an index's constituents, say).
 */
final readonly class ImportContext
{
    /**
     * @param array<string, mixed> $routeParameters the matched route's parameters, `_import` and `entity` included
     */
    public function __construct(
        public ?object $account,
        public array $routeParameters = [],
    ) {
    }

    /** A parameter of the route, or `null`. */
    public function parameter(string $name): mixed
    {
        return $this->routeParameters[$name] ?? null;
    }
}
