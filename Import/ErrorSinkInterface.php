<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * Where an import's errors go, ROW BY ROW, as {@see ImportRunner} finds them.
 *
 * ## The gap this closes
 *
 * ⚠️ {@see ImportReport} keeps only the first {@see ImportReport::MAX_RETAINED_ERRORS} — a 50 000-row
 * file with a wrong delimiter produces one error per row, and a screen was never meant to render
 * 50 000 of them. That cap is correct for the SCREEN and wrong for an operator who needs the
 * complete list to fix the file: they see "and 49 900 more", not which rows. A sink is how a caller
 * gets the complete list without asking `ImportReport` to hold it — it is handed each error the
 * moment `ImportRunner` records it, and never asked for the whole set at the end.
 *
 * ## Why this is not a `TabularWriterInterface`
 *
 * ⚠️ A writer's contract is `write(array $header, iterable $rows, callable $emit)` — it PULLS a
 * complete `iterable` and is done in one call. An import's errors are the opposite shape: they are
 * PUSHED, one at a time, synchronously, from inside a run that has not finished. Forcing a pull
 * shape onto a push producer needs either buffering everything first (exactly what this exists to
 * avoid) or a coroutine to interleave the two — real complexity for what a two-line `fputcsv` call
 * already does. {@see \Jul6Art\DataflowBundle\Import\Sink\CsvErrorSink} writes to a handle directly
 * instead, on the same dialect and through the same {@see \Jul6Art\DataflowBundle\Io\Guard\FormulaInjectionGuard}
 * a writer would use.
 */
interface ErrorSinkInterface
{
    /**
     * @param string $message a translation key, or a rendered validator message — the exact value
     *                        {@see ImportReport::recordError()} receives, unmodified
     */
    public function record(int $record, string $message): void;
}
