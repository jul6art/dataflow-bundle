<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures;

use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;

/**
 * A consumer of the `dataflow.tabular_reader` tag, so a test can see what the tag collects.
 *
 * ⚠️ The mirror of {@see TaggedWriters}, and only worth having once a SECOND reader exists: with
 * `CsvReader` alone, nothing iterated the tag and nothing could tell a definition that carries it
 * from one an iterator actually collects. `XlsxReader` (lot 2.1) is what makes content-based
 * resolution — trying each reader's `supports()` in turn — a real use of this tag rather than a
 * promise with one member.
 */
final readonly class TaggedReaders
{
    /**
     * @param iterable<TabularReaderInterface> $readers
     */
    public function __construct(
        public iterable $readers,
    ) {
    }
}
