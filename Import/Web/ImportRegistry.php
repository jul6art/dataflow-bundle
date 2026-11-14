<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The imports of the application, by key, in the order their priority gives — the order a screen lists them in.
 */
final readonly class ImportRegistry
{
    /**
     * @param iterable<string, ImportDefinitionInterface> $definitions tagged `dataflow.import`, indexed by `key`
     */
    public function __construct(
        private iterable $definitions,
    ) {
    }

    /**
     * @throws NotFoundHttpException an unknown key: a route names an import nobody declared, or a visitor typed one
     */
    public function get(string $key): ImportDefinitionInterface
    {
        foreach ($this->definitions as $name => $definition) {
            if ($name === $key) {
                return $definition;
            }
        }

        throw new NotFoundHttpException(\sprintf('No import is declared under "%s".', $key));
    }

    public function has(string $key): bool
    {
        foreach ($this->definitions as $name => $definition) {
            if ($name === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->definitions as $name => $definition) {
            $keys[] = $name;
        }

        return $keys;
    }
}
