<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures;

use Jul6Art\DataflowBundle\Io\TabularWriterInterface;

/**
 * A consumer of the `dataflow.tabular_writer` tag, so a test can see what the tag collects.
 *
 * ⚠️ A real service rather than an inspection of the container's tag map: what has to be true is
 * that a **tagged iterator injects the writers**, and a definition can carry a tag that no iterator
 * ever collects — which is exactly what v1.0.x shipped, in reverse.
 */
final readonly class TaggedWriters
{
    /**
     * @param iterable<TabularWriterInterface> $writers
     */
    public function __construct(
        public iterable $writers,
    ) {
    }
}
