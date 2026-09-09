<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Reader;

/**
 * Reads a tabular file record by record.
 *
 * ## The mirror of {@see \Jul6Art\DataflowBundle\Io\TabularWriterInterface}, and deliberately so
 *
 * A reader carries its own format settings in its constructor, exactly as a writer does. The
 * alternative — passing a dialect to `read()` — reads well for CSV and becomes a lie the day an
 * XLSX reader has to accept a `CsvDialect` it ignores. Symmetry is also what makes the pair
 * learnable: `new CsvWriter($dialect)` and `new CsvReader($dialect)` need explaining once.
 *
 * ## Records, not lines
 *
 * ⚠️ **The key a reader yields is a RECORD number, 1-based, header included.** It is what a user
 * needs in order to find the offending row again, and it is *not* always the line number in their
 * editor: a quoted field may contain newlines, so one record can span several lines. Calling it a
 * line would be wrong in exactly the files where finding the row matters most.
 *
 * ⚠️ **A blank line consumes a record number and yields nothing.** Skipping it silently would shift
 * every number after it, and an import report pointing at the wrong row is worse than no number.
 *
 * ## Laziness is part of the contract
 *
 * ⚠️ The return type is a `Generator` because a reader MUST NOT hold the file in memory — and, as
 * the report engine learned, the type alone proves nothing: a body that builds an array and then
 * `yield from`s it satisfies this signature. What proves it is a memory measurement, so that is
 * what the tests do.
 */
interface TabularReaderInterface
{
    /**
     * The format's short code, as an application names it in a URL or a form (`csv`, `xlsx`).
     */
    public function code(): string;

    /**
     * Can this reader make sense of these bytes?
     *
     * ⚠️ Answered from the CONTENT, never from the extension or the MIME type. A browser sends
     * `application/vnd.ms-excel` for a CSV saved out of Excel, so a MIME allow list that admits it
     * admits real `.xls` workbooks too.
     */
    public function supports(string $filePath): bool;

    /**
     * @return \Generator<int, list<string>> record number (1-based, header included) → cells
     *
     * @throws \Jul6Art\DataflowBundle\Exception\UnreadableFileException
     */
    public function read(string $filePath): \Generator;
}
