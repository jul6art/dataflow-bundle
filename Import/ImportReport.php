<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * What an import run did: how many rows landed, how many were skipped, and what went wrong where.
 *
 * ## Why the error list is capped and the count is not
 *
 * ⚠️ A file whose delimiter is wrong produces an error on **every** row. Keeping them all means a
 * 50 000-entry array in memory, handed to a Twig template that renders 50 000 rows into a page
 * nobody reads. So the report retains the first {@see self::MAX_RETAINED_ERRORS} and counts all of
 * them: `errorCount()` is the truth, `errors()` is the sample, and `errorsWereTruncated()` says so
 * out loud rather than letting a screen imply the file had exactly a hundred problems.
 *
 * A downloadable, complete error file is a separate mechanism — errors streamed to a writer as they
 * happen, never accumulated — and is a later lot. Capping here is what keeps that door open.
 *
 * ## The counters are disjoint, on purpose
 *
 * A row is imported, or skipped, or in error. `total()` is their sum and equals the number of data
 * records read. A row counted twice is how an import report comes to say more rows than the file
 * has, and the operator stops trusting all of it.
 */
final class ImportReport
{
    public const int MAX_RETAINED_ERRORS = 100;

    private int $imported = 0;

    private int $skipped = 0;

    private int $errorCount = 0;

    /** @var list<array{record: int, message: string}> */
    private array $errors = [];

    public function __construct(
        private readonly bool $dryRun = false,
    ) {
    }

    public function recordImported(): void
    {
        ++$this->imported;
    }

    public function recordSkipped(): void
    {
        ++$this->skipped;
    }

    /**
     * ⚠️ A message is EITHER a translation key — everything the mapper and the runner refuse — OR
     * an already-rendered validator message prefixed by its property path. The duality is real and
     * cannot be removed: the validator translates its own messages, in the user's locale, before
     * anyone here sees them. A screen resolves both by passing the message through the translator,
     * which returns an unknown key unchanged; that is the one call that is right for both cases.
     *
     * @param string $message a translation key, or a rendered validator message
     */
    public function recordError(int $record, string $message): void
    {
        ++$this->errorCount;

        if (\count($this->errors) < self::MAX_RETAINED_ERRORS) {
            $this->errors[] = ['record' => $record, 'message' => $message];
        }
    }

    /**
     * Rows that would have been written — or were, outside a dry run.
     *
     * ⚠️ Named without a tense because in a dry run nothing was imported at all. A screen must read
     * {@see self::isDryRun()} and word it accordingly; a report that says "1 240 imported" after a
     * dry run is the reason someone runs the real import twice.
     */
    public function imported(): int
    {
        return $this->imported;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * @return list<array{record: int, message: string}> at most {@see self::MAX_RETAINED_ERRORS}
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorsWereTruncated(): bool
    {
        return $this->errorCount > \count($this->errors);
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function total(): int
    {
        return $this->imported + $this->skipped + $this->errorCount;
    }
}
