<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;

/**
 * Builds {@see ImportPreview}: the first rows, and how many rows already exist.
 *
 * ⚠️ The two answer two different questions. The sample shows whether a column is READ right — a file whose first
 * and last names are swapped raises nothing, two strings are valid; the count shows what will be OVERWRITTEN — with
 * a policy that updates, every row found by its key is rewritten. Five rows say nothing of the four thousand after
 * them.
 */
final readonly class ImportPreviewBuilder
{
    /** Enough to see a shifted column, few enough for the screen to cost the same on fifty rows and fifty thousand. */
    private const int SAMPLE_SIZE = 5;

    /**
     * @param array<int, string> $mapping column position → field key
     */
    public function build(TabularReaderInterface $reader, string $filePath, array $mapping, ?DuplicateResolverInterface $resolver): ImportPreview
    {
        $sample = [];
        $rows = [];
        $first = true;

        foreach ($reader->read($filePath) as $cells) {
            // ⚠️ The first line is the HEADER: the reader yields it like the others, and counting it would make the
            // count wrong by exactly one — the kind of gap one then blames on the data.
            if ($first) {
                $first = false;

                continue;
            }

            $row = [];

            foreach ($mapping as $index => $field) {
                $row[$field] = (string) ($cells[$index] ?? '');
            }

            $rows[] = $row;

            if (\count($sample) < self::SAMPLE_SIZE) {
                $sample[] = $row;
            }
        }

        $updates = null === $resolver || [] === $rows ? 0 : \count($resolver->findExisting($rows));

        return new ImportPreview($sample, \count($rows), $updates, \count($rows) - $updates);
    }
}
