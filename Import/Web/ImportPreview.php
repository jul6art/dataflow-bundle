<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/**
 * What the run would do, said before it does it: the first rows read with the mapping shown, and the count.
 */
final readonly class ImportPreview
{
    /**
     * @param list<array<string, string>> $rows      the first rows, READ WITH THE MAPPING
     * @param int                         $total     the data rows of the file
     * @param int                         $updates   those that match a stored record
     * @param int                         $creations those that do not
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $updates,
        public int $creations,
    ) {
    }
}
