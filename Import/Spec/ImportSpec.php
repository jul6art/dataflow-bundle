<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Spec;

/**
 * Everything that describes one import run, validated on construction.
 *
 * ⚠️ **The mapping is keyed by column INDEX.** Two columns with the same header are a real file
 * shape, and a name-keyed mapping loses one of them without saying so. See
 * {@see \Jul6Art\DataflowBundle\Import\HeaderInspection}.
 *
 * ⚠️ **No dialect here.** A reader carries its own format settings, exactly as a writer does, so
 * that an XLSX reader is never handed a `CsvDialect` it has to ignore. The plan sketched this
 * object as holding one; the symmetry with the writer side is worth more than the sketch.
 *
 * ## The two options that change what "imported" means
 *
 * ⚠️ **`dryRun` validates and counts, and writes nothing.** It is not a rollback: nothing is
 * persisted, so nothing reaches the database and no trigger fires and no sequence advances. The
 * consequence is that it cannot catch a constraint only the database knows about — a unique index
 * the validator does not mirror. What it does catch is every row the mapper or the validator would
 * reject, which is what an operator actually needs before committing 5 000 rows.
 *
 * ⚠️ **`atomic` wraps the whole run in one transaction.** Batched imports are not atomic by nature:
 * with it off, a failure on batch 40 leaves batches 1 to 39 committed, and the operator has to work
 * out which rows made it. With it on — the default — a failure leaves the database as it was, at
 * the price of one long transaction. A very large import turns it off deliberately.
 */
final readonly class ImportSpec
{
    public const int DEFAULT_BATCH_SIZE = 50;
    public const int DEFAULT_MAX_ROWS = 10000;

    /**
     * @param array<int, string> $mapping column index → field key
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $filePath,
        public array $mapping,
        public bool $dryRun = false,
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
        public int $maxRows = self::DEFAULT_MAX_ROWS,
        public DuplicatePolicy $onDuplicate = DuplicatePolicy::Skip,
        public bool $atomic = true,
    ) {
        if ([] === $mapping) {
            throw new \InvalidArgumentException('An import needs at least one mapped column.');
        }

        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }

        if ($maxRows < 1) {
            throw new \InvalidArgumentException('The row cap must be at least 1.');
        }

        foreach ($mapping as $index => $field) {
            if ($index < 0) {
                throw new \InvalidArgumentException('A column index cannot be negative.');
            }

            if ('' === $field) {
                throw new \InvalidArgumentException(\sprintf('Column %d is mapped to an empty field.', $index));
            }
        }

        // ⚠️ Refused rather than resolved. Two columns feeding one field means the later one wins
        // by array order, which is not a decision anybody made — and the run would read a plausible
        // wrong column for every row of the file.
        if (\count(\array_unique($mapping)) !== \count($mapping)) {
            throw new \InvalidArgumentException('Two columns are mapped to the same field.');
        }
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return \array_values($this->mapping);
    }
}
