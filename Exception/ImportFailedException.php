<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Exception;

use Jul6Art\DataflowBundle\Import\ImportReport;

/**
 * A batch could not be written, so the run stopped.
 *
 * ⚠️ **It carries the partial report**, and a caller must show it. Once `flush()` throws, Doctrine
 * closes the entity manager: nothing further can be read or written through it, so there is no
 * "carry on with the next batch". What the operator needs at that point is not the stack trace but
 * how far the file got — which rows are in, and from which record to resume.
 *
 * ⚠️ **Whether the committed rows survive depends on `ImportSpec::$atomic`.** With it on, the
 * transaction was rolled back and `imported()` counts rows that no longer exist — so the message a
 * screen shows has to be worded from the flag, not from the counter. `wasRolledBack()` is that flag,
 * carried here rather than re-derived, because the spec is not in scope where the exception is
 * caught.
 */
final class ImportFailedException extends \RuntimeException
{
    public function __construct(
        private readonly ImportReport $report,
        private readonly int $record,
        private readonly bool $rolledBack,
        \Throwable $previous,
    ) {
        parent::__construct('dataflow.import.error.batch_failed', previous: $previous);
    }

    public function report(): ImportReport
    {
        return $this->report;
    }

    /**
     * The record the run reached. Not necessarily the offending one: a batch fails as a whole, and
     * the database names a constraint rather than a row.
     */
    public function record(): int
    {
        return $this->record;
    }

    public function wasRolledBack(): bool
    {
        return $this->rolledBack;
    }
}
