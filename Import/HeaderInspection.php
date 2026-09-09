<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * What {@see HeaderInspector} found in a file's header row: a suggested mapping, and everything the
 * screen has to ask about rather than decide.
 *
 * ⚠️ **The mapping is keyed by column INDEX, not by header name.** A file with two columns both
 * called `email` collapses into one entry in a name-keyed map, and the second silently wins — the
 * import then reads the wrong column and every row is subtly wrong rather than obviously broken.
 * The index is the only identifier a column actually has.
 */
final readonly class HeaderInspection
{
    /**
     * @param list<string>       $headers   the header cells, raw and in file order
     * @param array<int, string> $mapping   column index → field key; only unambiguous matches
     * @param list<int>          $ambiguous indices whose header matches another column's
     * @param list<int>          $unknown   indices no field matched, blank cells included
     * @param list<string>       $missing   field keys no column supplied
     */
    public function __construct(
        public array $headers,
        public array $mapping,
        public array $ambiguous = [],
        public array $unknown = [],
        public array $missing = [],
    ) {
    }

    /**
     * True when the file can be imported with no human intervention.
     *
     * ⚠️ An unknown column does NOT make it undecidable: a file carrying an extra column the
     * application does not know about is the normal case, not an error. What blocks is a collision
     * — two columns claiming the same field — because there the machine has no basis to choose.
     */
    public function isUnambiguous(): bool
    {
        return [] === $this->ambiguous && [] !== $this->mapping;
    }
}
